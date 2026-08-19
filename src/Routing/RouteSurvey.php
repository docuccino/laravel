<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * What the router publishes, grouped by first URI segment. It answers the one question a newcomer
 * cannot: does the shipped `api/*` include pattern match anything in THIS application, and if not,
 * where do its routes actually live.
 *
 * Only the two narrowings that precede any document pattern are applied — a HEAD-only route and a
 * vendor package's controller are not candidates for any document — so a prefix reported here is one
 * the default document would document if its pattern named it.
 *
 * @internal
 */
final class RouteSurvey
{
    public function __construct(
        private readonly Router $router,
        private readonly RouteReflector $reflector = new RouteReflector,
        // Supplied by the service provider with base_path('vendor'); null keeps vendor routes in.
        private readonly ?VendorRoutePolicy $vendorPolicy = null,
    ) {}

    /**
     * Every candidate route path, without its leading slash and sorted. Two verbs on one URI are two
     * routes, and count as two — the same arithmetic `routes.include` is judged by.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];

        /** @var iterable<Route> $routes */
        $routes = $this->router->getRoutes();

        foreach ($routes as $route) {
            if ($this->documentable($route)) {
                $paths[] = ltrim($route->uri(), '/');
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * The first segment of each candidate path with its route count, busiest first and ties broken by
     * name — never by registration order, so the same application always reads the same way.
     *
     * @return list<RoutePrefix>
     */
    public function prefixes(): array
    {
        $counts = [];

        foreach ($this->paths() as $path) {
            $prefix = self::prefix($path);
            $counts[$prefix] = ($counts[$prefix] ?? 0) + 1;
        }

        $prefixes = [];
        foreach ($counts as $prefix => $count) {
            $prefixes[] = new RoutePrefix((string) $prefix, $count);
        }

        usort($prefixes, static fn (RoutePrefix $a, RoutePrefix $b): int => [$b->count, $a->prefix] <=> [$a->count, $b->prefix]);

        return $prefixes;
    }

    /** A route no document could ever include, whatever its patterns say. */
    private function documentable(Route $route): bool
    {
        $methods = array_filter(
            $route->methods(),
            static fn (mixed $method): bool => is_string($method) && strtoupper($method) !== 'HEAD',
        );

        if ($methods === []) {
            return false;
        }

        if ($this->vendorPolicy === null) {
            return true;
        }

        // Vendor exclusion is a question about the controller's FILE, so it needs the reflection.
        $reflected = $this->reflector->forRoute($route);
        $controllerFile = $reflected !== null && $reflected->controllerClass !== null
            ? $reflected->actionRef->file
            : null;

        return ! $this->vendorPolicy->isVendorFile($controllerFile);
    }

    /** The segment a `routes.include` pattern would name; `/` for the root route, which has none. */
    private static function prefix(string $path): string
    {
        $segment = explode('/', $path)[0];

        return $segment === '' ? '/' : $segment;
    }
}
