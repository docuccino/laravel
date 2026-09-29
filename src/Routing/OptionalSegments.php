<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Illuminate\Routing\Route;
use ReflectionFunctionAbstract;
use ReflectionNamedType;

/**
 * The value the action receives for a path segment a request leaves off ({@see RouteTemplate::optional()}):
 * the route's `->defaults()` value, or, where the route sets none, the action parameter's own default.
 *
 * @internal
 */
final class OptionalSegments
{
    /**
     * The value the action receives when a request leaves the optional segment off, where that value is
     * a string or an integer the segment itself could carry — the requirement the router compiled for it
     * accepts it — so sending it answers exactly as leaving it off does. Any other value has no spelling
     * in the path, so nothing is said about it.
     */
    public static function defaultOf(Route $route, ?ReflectionFunctionAbstract $action, string $name): ?string
    {
        if (! in_array($name, RouteTemplate::optional($route->uri()), true)) {
            return null;
        }

        // A route default is what the action receives whatever it is, so one no segment can carry
        // answers the question as surely as one it can; only a `null` is none, since the dispatcher
        // drops null parameters and the action's own default fills them.
        $routeDefault = $route->defaults[$name] ?? null;
        $value = $routeDefault === null ? self::actionDefault($action, $name) : self::segment($routeDefault);
        $requirement = RouteTemplate::requirement($route, $name);
        if ($value === null || $requirement === null) {
            return null;
        }

        return preg_match('{^(?:'.$requirement.')$}sDu', $value) === 1 ? $value : null;
    }

    /**
     * The route's own defaults for its optional segments, as cache-key inputs: the action's parameter
     * defaults are in the action's file, which keys the fragment already.
     *
     * @return list<string>
     */
    public static function cacheInputs(Route $route): array
    {
        $inputs = [];
        foreach (RouteTemplate::optional($route->uri()) as $name) {
            $value = $route->defaults[$name] ?? null;
            if ($value !== null) {
                $inputs[] = 'default:'.$name.'='.(is_string($value) || is_int($value) ? $value : get_debug_type($value));
            }
        }

        return $inputs;
    }

    /** The value as the segment that sends it, or null when no segment can. */
    private static function segment(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    /** The default of the action parameter the segment fills, when it is a string or an integer. */
    private static function actionDefault(?ReflectionFunctionAbstract $action, string $name): ?string
    {
        foreach ($action?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() !== $name || ! $parameter->isDefaultValueAvailable()) {
                continue;
            }

            // An injected class is resolved by the container, never filled from the segment.
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                return null;
            }

            return self::segment($parameter->getDefaultValue());
        }

        return null;
    }
}
