<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Illuminate\Routing\Route;

/**
 * The column each `{param:column}` in a route template binds on. Laravel parses the `:column` out of
 * `uri()` and records it here instead, so `{post}` and `{post:slug}` are the same URI by the time
 * anything downstream sees one — which is why both the descriptor's cache key and the route context
 * read the fields from this one place rather than each re-deriving them.
 *
 * @internal
 */
final class RouteBindingFields
{
    /**
     * @return array<string, string>
     */
    public static function of(Route $route): array
    {
        $fields = [];
        foreach ($route->bindingFields() as $parameter => $field) {
            if (is_string($parameter) && is_string($field)) {
                $fields[$parameter] = $field;
            }
        }

        return $fields;
    }

    /**
     * The same fields as cache-key inputs, sorted so the key is a function of the route rather than of
     * the order Laravel happened to parse the URI in.
     *
     * @return list<string>
     */
    public static function cacheInputs(Route $route): array
    {
        $inputs = [];
        foreach (self::of($route) as $parameter => $field) {
            $inputs[] = 'binding:'.$parameter.'='.$field;
        }
        sort($inputs);

        return $inputs;
    }
}
