<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Docuccino\Attributes\ExcludeFromDocs;
use Docuccino\Attributes\InDocs;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\RouteResolver;
use Docuccino\Core\Support\Glob;
use Docuccino\Laravel\Support\MiddlewareAliases;
use Docuccino\Laravel\Support\MiddlewareName;
use Docuccino\Laravel\Support\MiddlewareRegistrations;
use Docuccino\Laravel\Support\MiddlewareResolution;
use Docuccino\Laravel\Support\UnknownDocumentPins;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * The built-in {@see RouteResolver}: turns the Laravel router into {@see RouteDescriptor}s (design
 * §Route discovery), applying the document's include/exclude patterns and closure filter and honouring
 * `#[ExcludeFromDocs]` / `#[InDocs]`. Controller and closure routes both work.
 *
 * A `Route::fallback()` is described and yielded like any other route — the document's own filters get
 * their say first — carrying the flag the generator omits and reports it on.
 *
 * An `#[InDocs]` key naming no configured document is recorded while filtering and drained by the
 * generator ({@see takeDiagnostics()}): the exclusion it causes leaves no trace in the document, so
 * nothing downstream could ever notice it.
 */
final class LaravelRouteResolver implements RouteResolver
{
    /** @var list<Diagnostic> */
    private array $middlewareDiagnostics = [];

    private readonly MiddlewareRegistrations $registrations;

    public function __construct(
        private readonly Router $router,
        private readonly RouteReflector $reflector = new RouteReflector,
        private readonly AttributeCollector $attributes = new AttributeCollector,
        private readonly ResolvedRouteIndex $index = new ResolvedRouteIndex,
        // Supplied by the service provider with base_path('vendor'); null disables vendor exclusion.
        private readonly ?VendorRoutePolicy $vendorPolicy = null,
        private readonly UnknownDocumentPins $pins = new UnknownDocumentPins,
        // How the router gets the middleware registrations it does not have until something constructs
        // the HTTP kernel, which a console build does not ({@see MiddlewareRegistrations}).
        ?Container $app = null,
    ) {
        $this->registrations = new MiddlewareRegistrations($router, $app);
    }

    /**
     * What the walk found and could not say for itself, emptied as it is read: the `#[InDocs]` keys
     * naming no configured document, whose only effect is a route that is not there
     * ({@see UnknownDocumentPins}), and what the middleware maps could not answer
     * ({@see MiddlewareRegistrations}).
     * Drained by the generator once the walk is complete.
     *
     * @return list<Diagnostic>
     */
    public function takeDiagnostics(): array
    {
        $middleware = $this->middlewareDiagnostics;
        $this->middlewareDiagnostics = [];

        return [...$this->pins->take(), ...$middleware];
    }

    public function resolve(DocumentConfig $document): iterable
    {
        /** @var iterable<Route> $routes */
        $routes = $this->router->getRoutes();

        // Build-constant, and read once rather than per route: filling the router costs a container
        // resolution and the alias map a reflection and an array merge ({@see MiddlewareRegistrations}).
        ['aliases' => $aliases, 'groups' => $groups] = $this->registrations->read($this->record(...));

        foreach ($routes as $route) {
            $descriptor = $this->describe($route, $aliases, $groups);

            if (! $this->passesFilters($descriptor, $document)) {
                continue;
            }

            // Reflect once; the builder reuses this via the shared index.
            $reflected = $this->reflector->forRoute($route);
            if (! $this->passesAttributes($reflected, $descriptor, $document)) {
                continue;
            }

            // Vendor exclusion needs the resolved controller file, hence after reflection.
            if ($this->excludedAsVendor($reflected, $document)) {
                continue;
            }

            $this->index->put($descriptor, $route, $reflected);

            yield $descriptor;
        }
    }

    /**
     * @param  array<string, string>  $aliases
     * @param  array<array-key, mixed>  $groups
     */
    private function describe(Route $route, array $aliases, array $groups): RouteDescriptor
    {
        $gathered = $this->expandAll(self::strings($route->gatherMiddleware()), $groups);
        $excluded = $this->expandAll(self::strings($route->excludedMiddleware()), $groups);

        $descriptor = new RouteDescriptor(
            methods: self::strings($route->methods()),
            uri: '/'.ltrim($route->uri(), '/'),
            name: $route->getName(),
            action: $route->getActionName(),
            middleware: self::middleware($gathered, $excluded, $aliases),
            // `->withTrashed()` puts a note and a fact on every bound parameter but touches nothing
            // else the signature already carries, so it has to fold itself in or a warm build keeps
            // answering with the note the route dropped. Binding fields are the same shape of input for
            // a stronger reason: Laravel strips `:slug` out of `uri()`, so `{post}` and `{post:slug}`
            // are the same signature and the same key while typing the parameter differently.
            cacheInputs: [
                ...($route->allowsTrashedBindings() ? ['trashed'] : []),
                ...RouteBindingFields::cacheInputs($route),
            ],
            domain: RouteHost::of($route),
            fallback: $route->isFallback,
        );

        $unmatched = MiddlewareResolution::unmatchedExclusions($gathered, $excluded, $aliases);
        if ($unmatched !== []) {
            $this->record(MiddlewareAliases::unmatchedExclusion($descriptor->signature(), $unmatched));
        }

        return $descriptor;
    }

    private function record(Diagnostic $diagnostic): void
    {
        $this->middlewareDiagnostics[] = $diagnostic;
    }

    /**
     * The route's middleware set: `withoutMiddleware(...)` exclusions subtracted the way the framework
     * subtracts them, in its resolved-class space ({@see MiddlewareResolution}, and
     * {@see MiddlewareAliases} for the map), so a route that opts out of `throttle:api` or `auth` isn't
     * documented with a 429/401 it never enforces, and one that opts out in a spelling the framework does NOT equate keeps the response it
     * does. What survives is handed on in the spelling the route wrote, because the readers downstream
     * are tables of alias AND class names and rewriting an entry into one vocabulary hides it from
     * whoever speaks the other. The one rewrite that hides nothing is dropping a leading `\`, which is
     * not part of a class name ({@see MiddlewareName::normalize()}).
     *
     * @param  list<string>  $gathered
     * @param  list<string>  $excluded
     * @param  array<string, string>  $aliases
     * @return list<string>
     */
    private static function middleware(array $gathered, array $excluded, array $aliases): array
    {
        $kept = array_map(
            MiddlewareName::normalize(...),
            MiddlewareResolution::subtract($gathered, $excluded, $aliases),
        );

        return array_values(array_unique($kept));
    }

    /**
     * Every entry with the kernel groups among them expanded to their members (recursively,
     * cycle-guarded), so something registered app-wide via a group — Sanctum's stateful middleware on
     * `api`, a group's `throttle:` — is read as if it were on the route. The members stay in the
     * short-form vocabulary the detectors read, so this widens detection without the wholesale alias
     * resolution `Router::gatherRouteMiddleware()` does.
     *
     * @param  list<string>  $entries
     * @param  array<array-key, mixed>  $groups
     * @return list<string>
     */
    private function expandAll(array $entries, array $groups): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $this->expandMiddleware($entry, $groups, $out, []);
        }

        return $out;
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
            // A member that names another group is looked up before its parameters are read, which is
            // the order the framework's own expansion uses; anything else has them reattached the way a
            // GROUP reattaches them ({@see MiddlewareName::asGroupMember()}).
            $this->expandMiddleware(
                array_key_exists($member, $groups) ? $member : MiddlewareName::asGroupMember($member),
                $groups,
                $out,
                [...$visiting, $entry],
            );
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
     * Whether the default vendor exclusion drops this route. A closure or unreflectable action has no
     * file to judge, so it's never excluded.
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

    private function passesAttributes(?ReflectedAction $reflected, RouteDescriptor $descriptor, DocumentConfig $document): bool
    {
        if ($reflected === null) {
            return true; // unreflectable actions still surface (as skeletons) downstream
        }

        $attributes = $this->attributes->collect($reflected);

        if ($attributes->has(ExcludeFromDocs::class)) {
            return false;
        }

        $inDocs = $attributes->first(InDocs::class);
        if ($inDocs === null) {
            return true;
        }

        $this->pins->record($inDocs, $descriptor->signature());

        return in_array($document->key, $inDocs->documents, true);
    }

    /**
     * The include/exclude wildcards, read by the grammar the whole product reads a wildcard by. It was
     * `Str::is` and still means exactly that — {@see Glob} is that grammar written where core can reach
     * it, so a version change's operation scope globs the way a route filter does.
     *
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $path, array $patterns): bool
    {
        return Glob::matchesAny($patterns, $path);
    }
}
