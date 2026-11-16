<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Closure;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteBinding;
use Illuminate\Routing\Router;
use ReflectionFunction;

/**
 * The facts about HOW a route's bindings resolve that the template does not carry: which parameters
 * the framework looks up inside their parent, which ones the application resolves with a binder of its
 * own instead of by implicit binding, and which it binds to a model with `Route::model()`. Both change what the server accepts, so both are
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
     * The path parameters the application registered its own binder for (`Route::bind`). Whatever such a
     * binder matches on lives in a closure body, so nothing static can say which column it is. A
     * `Route::model()` binding is not one of these: its closure is the framework's, and what it resolves
     * is read instead ({@see explicitModels()}).
     *
     * @param  list<string>  $pathParameters
     * @return list<string>
     */
    public static function custom(Router $router, array $pathParameters): array
    {
        $models = self::explicitModels($router, $pathParameters);

        $custom = [];
        foreach ($pathParameters as $name) {
            if ($router->getBindingCallback($name) !== null && ! isset($models[$name])) {
                $custom[] = $name;
            }
        }

        return $custom;
    }

    /**
     * Path parameter → the model a `Route::model()` binding resolves it to. The framework builds that
     * binder itself ({@see RouteBinding::forModel()}), around the class it was handed, and it resolves the
     * segment through the model's own route key — the route's `{param:column}` never reaches it. Read off
     * the closure the framework built, so a binder an application wrote that merely resembles it is not
     * mistaken for one. One given a fallback callback is the application's binder as much as the
     * framework's: the fallback answers whatever the key does not find, so it stays custom.
     *
     * @param  list<string>  $pathParameters
     * @return array<string, string>
     */
    public static function explicitModels(Router $router, array $pathParameters): array
    {
        $models = [];

        foreach ($pathParameters as $name) {
            $callback = $router->getBindingCallback($name);
            if (! $callback instanceof Closure) {
                continue;
            }

            $function = new ReflectionFunction($callback);
            $used = $function->getClosureUsedVariables();
            $class = $used['class'] ?? null;

            // A fallback the application passed decides in a closure of its own what a miss resolves to.
            if ($function->getClosureScopeClass()?->getName() === RouteBinding::class && is_string($class) && class_exists($class)
                && array_key_exists('callback', $used) && $used['callback'] === null) {
                $models[$name] = $class;
            }
        }

        return $models;
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

        foreach (self::explicitModels($router, self::parameterNames($route)) as $name => $model) {
            $inputs[] = 'model:'.$name.'='.$model;
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
