<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Provenance\SourcePathResolver;
use Docuccino\Core\TypeGrammar\DocBlockReader;
use Illuminate\Foundation\Http\FormRequest as LaravelFormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionNamedType;

/**
 * Builds the framework-agnostic {@see RouteContext} for one discovered route: locates the Laravel
 * {@see Route}, reflects the handler, collects attributes (method beats class), reads the docblock
 * prose, and derives path parameters and route-model bindings. Null when the action can't be
 * reflected, which is the generator's cue to emit a skeleton.
 *
 * @internal
 */
final class RouteContextBuilder
{
    public function __construct(
        private readonly Router $router,
        private readonly ?SourcePathResolver $pathResolver = null,
        private readonly RouteReflector $reflector = new RouteReflector,
        private readonly AttributeCollector $attributes = new AttributeCollector,
        private readonly DocBlockReader $docblocks = new DocBlockReader,
        private readonly ResolvedRouteIndex $index = new ResolvedRouteIndex,
    ) {}

    /** The extension set travels whole — see {@see ResolvedExtensions} for why it isn't unpacked here. */
    public function build(
        RouteDescriptor $descriptor,
        DocumentConfig $document,
        TypeEngine $engine,
        ResolvedExtensions $extensions,
        ComponentRegistry $components,
        ?string $method = null,
        ?string $operationId = null,
    ): ?RouteContext {
        // Reuse the Route + reflection the resolver already produced. On a container miss the index is
        // empty, so fall back to a lookup and fresh reflection.
        $resolved = $this->index->get($descriptor);
        $route = $resolved['route'] ?? $this->locate($descriptor);
        if ($route === null) {
            return null;
        }

        $reflected = $resolved !== null ? $resolved['reflected'] : $this->reflector->forRoute($route);
        if ($reflected === null) {
            return null;
        }

        $prose = $this->docblocks->read($reflected->reflection->getDocComment() ?: null);

        [$pathParameters, $optional] = $this->pathParameters($descriptor->uri);

        $documentedMethod = $method ?? $descriptor->primaryMethod();
        $signature = $descriptor->signature($documentedMethod);

        $context = new RouteContext(
            route: $descriptor,
            actionRef: $reflected->actionRef,
            attributes: $this->attributes->collect(
                $reflected,
                static function (Diagnostic $diagnostic) use ($components): void {
                    $components->addDiagnostic($diagnostic);
                },
                $signature,
            ),
            engine: $engine,
            document: $document,
            extensions: $extensions,
            pathParameters: $pathParameters,
            optionalPathParameters: $optional,
            routeBindings: $this->routeBindings($reflected, $pathParameters),
            routeBindingFields: self::bindingFields($route, $pathParameters),
            summary: $prose['summary'],
            description: $prose['description'],
            components: $components,
            pathResolver: $this->pathResolver,
            documentedMethod: $documentedMethod,
            allowsTrashedBindings: $route->allowsTrashedBindings(),
            formRequestClass: $this->formRequestClass($reflected),
            operationId: $operationId,
            deprecated: $prose['deprecated'],
            deprecationReason: $prose['deprecationReason'],
        );

        // Class-level attributes walk the controller's parents, so the whole hierarchy's files key the
        // fragment — an attribute added to a base controller must retire warm fragments.
        if ($reflected->controllerClass !== null) {
            $context->recordDependencyFiles(DeclarationFiles::of($reflected->controllerClass));
        }

        return $context;
    }

    /**
     * The FormRequest type-hinted on the action, resolved once here so rule recovery and the
     * implicit-403 authorize probe both read it off the context rather than reflecting again.
     *
     * `Illuminate\Foundation` is the one Illuminate namespace the adapter names without requiring:
     * it ships only in `laravel/framework`, which has no split package to depend on. That is safe
     * rather than lucky — nothing here LOADS the class. `::class` is a compile-time string and
     * `is_subclass_of()` walks the ancestry of `$name` and compares names, so the needle is never
     * autoloaded, and it can only ever match in an application that declares a FormRequest subclass
     * — which is an application that has the framework.
     *
     * @return class-string<LaravelFormRequest>|null
     */
    private function formRequestClass(ReflectedAction $action): ?string
    {
        foreach ($action->reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();
            if (is_subclass_of($name, LaravelFormRequest::class)) {
                /** @var class-string<LaravelFormRequest> $name */
                return $name;
            }
        }

        return null;
    }

    /**
     * The degraded lookup for a descriptor the shared index never saw. A URI and a method name one
     * route only until two hosts share them, so the host decides between them.
     *
     * The fallback below applies ONLY to a descriptor that names no host, which is what a resolver that
     * doesn't model hosts produces: it has said nothing to choose by, so the first candidate is the best
     * answer available. A descriptor that DOES name a host and finds no route on it gets a skeleton —
     * handing it a sibling bound elsewhere would document that sibling's middleware, bindings and action
     * under a host it does not answer on, which is worse than saying nothing.
     */
    private function locate(RouteDescriptor $descriptor): ?Route
    {
        /** @var iterable<Route> $routes */
        $routes = $this->router->getRoutes();

        $fallback = null;
        foreach ($routes as $route) {
            if ('/'.ltrim($route->uri(), '/') !== $descriptor->uri) {
                continue;
            }

            if (array_values(array_filter($route->methods(), 'is_string')) !== $descriptor->methods) {
                continue;
            }

            if (RouteHost::of($route) === $descriptor->domain) {
                return $route;
            }

            $fallback ??= $route;
        }

        return $descriptor->domain === null ? $fallback : null;
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function pathParameters(string $uri): array
    {
        preg_match_all('/\{([^}]+)}/', $uri, $matches);

        $names = [];
        $optional = [];
        foreach ($matches[1] as $raw) {
            $optionalParam = str_ends_with($raw, '?');
            $name = rtrim($raw, '?');
            $names[] = $name;
            if ($optionalParam) {
                $optional[] = $name;
            }
        }

        return [$names, $optional];
    }

    /**
     * The binding fields restricted to parameters the template actually declares — nothing else could
     * consume one.
     *
     * @param  list<string>  $pathParameters
     * @return array<string, string>
     */
    private static function bindingFields(Route $route, array $pathParameters): array
    {
        return array_filter(
            RouteBindingFields::of($route),
            static fn (string $parameter): bool => in_array($parameter, $pathParameters, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  list<string>  $pathParameters
     * @return array<string, string>
     */
    private function routeBindings(ReflectedAction $action, array $pathParameters): array
    {
        $bindings = [];
        foreach ($action->reflection->getParameters() as $parameter) {
            if (! in_array($parameter->getName(), $pathParameters, true)) {
                continue;
            }

            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $bindings[$parameter->getName()] = $type->getName();
            }
        }

        return $bindings;
    }
}
