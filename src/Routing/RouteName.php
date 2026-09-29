<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Illuminate\Routing\Route;

/**
 * The name the application gave a route. Caching routes names every unnamed one `generated::` plus a
 * random string, which the application never wrote and a fresh cache rewrites, so that is no name.
 *
 * @internal
 */
final class RouteName
{
    public static function of(Route $route): ?string
    {
        $name = $route->getName();

        return $name === null || $name === '' || str_starts_with($name, 'generated::') ? null : $name;
    }
}
