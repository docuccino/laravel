<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Docuccino\Attributes\ExcludeFromDocs;
use Docuccino\Attributes\InDocs;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\RouteResolver;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * The built-in {@see RouteResolver}: turns the Laravel router into {@see RouteDescriptor}s
 * (design §Route discovery). Applies the document's include/exclude wildcard patterns and its
 * optional closure filter, and honours the `#[ExcludeFromDocs]` and `#[InDocs]` attributes. Both
 * controller and closure routes are supported.
 */
final class LaravelRouteResolver implements RouteResolver
{
    public function __construct(
        private readonly Router $router,
        private readonly RouteReflector $reflector = new RouteReflector,
        private readonly AttributeCollector $attributes = new AttributeCollector,
        private readonly ResolvedRouteIndex $index = new ResolvedRouteIndex,
        // Supplied by the service provider with base_path('vendor'); null disables vendor exclusion.
        private readonly ?VendorRoutePolicy $vendorPolicy = null,
    ) {}

    public function resolve(DocumentConfig $document): iterable
    {
        /** @var iterable<Route> $routes */
        $routes = $this->router->getRoutes();

        foreach ($routes as $route) {
            $descriptor = $this->describe($route);

            if (! $this->passesFilters($descriptor, $document)) {
                continue;
            }

            // Reflect once here; the builder reuses this via the shared index — no re-locate/reflect.
            $reflected = $this->reflector->forRoute($route);
            if (! $this->passesAttributes($reflected, $document)) {
                continue;
            }

            // Default vendor exclusion (route:list --except-vendor semantics): needs the resolved
            // controller file, so it runs post-reflection and after the include/exclude/closure gate.
            if ($this->excludedAsVendor($reflected, $document)) {
                continue;
            }

            $this->index->put($descriptor, $route, $reflected);

            yield $descriptor;
        }
    }

    private function describe(Route $route): RouteDescriptor
    {
        return new RouteDescriptor(
            methods: self::strings($route->methods()),
            uri: '/'.ltrim($route->uri(), '/'),
            name: $route->getName(),
            action: $route->getActionName(),
            middleware: $this->gatherMiddleware($route),
        );
    }

    /**
     * The route's gathered middleware with kernel middleware GROUPS expanded to their members
     * (recursively, cycle-guarded), so middleware registered app-wide via a group — e.g. Sanctum's
     * `EnsureFrontendRequestsAreStateful` prepended to the `api` group by `statefulApi()`, or a
     * group's `throttle:` — is detected the same as if declared on the route. Aliases and
     * `alias:params` entries are left verbatim (the detectors read their short forms), so this widens
     * detection without the wholesale alias resolution `Router::gatherRouteMiddleware()` would do.
     *
     * `withoutMiddleware(...)` exclusions are honoured the way {@see Router::resolveMiddleware()}
     * does: each excluded entry is expanded through the same groups and then removed from the gathered
     * set, so a route that opts out of `throttle:api` or `auth` is not documented with a 429/401 (or
     * a security requirement) it never enforces. The match is in our short-form vocabulary rather than
     * Laravel's resolved-FQCN space, mirroring the alias-preserving gather above.
     *
     * @return list<string>
     */
    private function gatherMiddleware(Route $route): array
    {
        $groups = $this->router->getMiddlewareGroups();

        $out = [];
        foreach (self::strings($route->gatherMiddleware()) as $entry) {
            $this->expandMiddleware($entry, $groups, $out, []);
        }

        $excluded = [];
        foreach (self::strings($route->excludedMiddleware()) as $entry) {
            $this->expandMiddleware($entry, $groups, $excluded, []);
        }

        if ($excluded !== []) {
            $out = array_diff($out, $excluded);
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<array-key, mixed>  $groups
     * @param  list<string>  $out
     * @param  list<string>  $visiting  the group names currently being expanded (cycle guard)
     */
    private function expandMiddleware(string $entry, array $groups, array &$out, array $visiting): void
    {
        if (! array_key_exists($entry, $groups) || in_array($entry, $visiting, true)) {
            $out[] = $entry;

            return;
        }

        $members = is_array($groups[$entry]) ? $groups[$entry] : [];
        foreach (self::strings($members) as $member) {
            $this->expandMiddleware($member, $groups, $out, [...$visiting, $entry]);
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_filter($values, 'is_string'));
    }

    private function passesFilters(RouteDescriptor $descriptor, DocumentConfig $document): bool
    {
        if ($descriptor->primaryMethod() === 'head') {
            return false;
        }

        $path = ltrim($descriptor->uri, '/');

        if ($document->routeInclude !== [] && ! $this->matchesAny($path, $document->routeInclude)) {
            return false;
        }

        if ($this->matchesAny($path, $document->routeExclude)) {
            return false;
        }

        $filter = $document->routeFilter;

        return ! (is_callable($filter) && $filter($descriptor) === false);
    }

    /**
     * Whether a route is dropped by the default vendor exclusion. A closure / unreflectable action
     * (no controller class) is passed a null file and is never excluded; an application controller's
     * file is not under vendor/, so it passes too.
     */
    private function excludedAsVendor(?ReflectedAction $reflected, DocumentConfig $document): bool
    {
        if ($this->vendorPolicy === null) {
            return false;
        }

        $controllerFile = $reflected !== null && $reflected->controllerClass !== null
            ? $reflected->actionRef->file
            : null;

        return $this->vendorPolicy->excludesVendorRoute($controllerFile, $document->includeVendor);
    }

    private function passesAttributes(?ReflectedAction $reflected, DocumentConfig $document): bool
    {
        if ($reflected === null) {
            return true; // unreflectable actions still surface (as skeletons) downstream
        }

        $attributes = $this->attributes->collect($reflected);

        if ($attributes->has(ExcludeFromDocs::class)) {
            return false;
        }

        $inDocs = $attributes->first(InDocs::class);
        if ($inDocs !== null && ! in_array($document->key, $inDocs->documents, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
