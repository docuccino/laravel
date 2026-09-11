<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * The two facts about HOW a route's bindings resolve that the template does not carry: which
 * parameters the framework looks up inside their parent, and which ones the application resolves with
 * a binder of its own instead of by implicit binding. Both change what the server accepts, so both are
 * read here once and folded into the descriptor's cache inputs from the same place.
 *
 * The scoping rule is the framework's own, transcribed from `ImplicitRouteBinding`: a parameter is
 * scoped when a preceding parameter binds something routable, scoping is not switched off, and either
 * the route asked for it or the parameter names its own column. Reading fewer of those conditions than
 * the framework does would publish scoping on a route that has none.
 *
 * @internal
 */
final class RouteBindingResolution
{
    /**
     * Path parameter → the parameter it is resolved WITHIN, for the subset the framework scopes.
     *
     * @param  list<string>  $pathParameters  in template order
     * @param  array<string, string>  $bindings  path parameter → bound class FQCN
     * @return array<string, string>
     */
    public static function scoped(Route $route, array $pathParameters, array $bindings): array
    {
        if ($route->preventsScopedBindings()) {
            return [];
        }

        $fields = RouteBindingFields::of($route);
        $enforced = $route->enforcesScopedBindings();

        $scoped = [];
        $parent = null;
        foreach ($pathParameters as $name) {
            $binding = $bindings[$name] ?? null;
            $routable = $binding !== null && is_subclass_of($binding, UrlRoutable::class);

            if ($parent !== null && $routable && ($enforced || isset($fields[$name]))) {
                $scoped[$name] = $parent;
            }

            // Only a routable parameter can be scoped TO: the framework asks the resolved parent to
            // resolve its child, and anything else has no relation to ask down. A parameter that is
            // not routable is not a parent either, so the chain restarts at the next one that is.
            $parent = $routable ? $name : null;
        }

        return $scoped;
    }

    /**
     * The path parameters the application registered its own binder for (`Route::bind`,
     * `Route::model`). Whatever such a binder matches on lives in a closure body, so nothing static can
     * say which column it is.
     *
     * @param  list<string>  $pathParameters
     * @return list<string>
     */
    public static function custom(Router $router, array $pathParameters): array
    {
        $custom = [];
        foreach ($pathParameters as $name) {
            if ($router->getBindingCallback($name) !== null) {
                $custom[] = $name;
            }
        }

        return $custom;
    }

    /**
     * The same two facts as cache-key inputs. Neither is knowable from the method, URI, action or
     * middleware the signature already carries: `->scopeBindings()` is a flag on the route, and a
     * registered binder is global router state a service provider set up, so a warm fragment would
     * otherwise keep publishing what the application stopped doing. Sorted, so the key is a function
     * of the route rather than of the order its parameters were parsed in.
     *
     * @return list<string>
     */
    public static function cacheInputs(Router $router, Route $route): array
    {
        $inputs = [];

        if ($route->enforcesScopedBindings()) {
            $inputs[] = 'scoped';
        }
        if ($route->preventsScopedBindings()) {
            $inputs[] = 'unscoped';
        }

        foreach (self::custom($router, self::parameterNames($route)) as $name) {
            $inputs[] = 'bound:'.$name;
        }

        sort($inputs);

        return $inputs;
    }

    /**
     * @return list<string>
     */
    private static function parameterNames(Route $route): array
    {
        return array_values(array_filter($route->parameterNames(), 'is_string'));
    }
}
