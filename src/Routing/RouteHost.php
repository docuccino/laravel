<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Illuminate\Routing\Route;

/**
 * The host a route answers on, as {@see RouteDescriptor::$domain} spells it. `Route::domain()` accepts
 * a scheme and Laravel strips it, so this is host[:port], lower-cased — hosts are case-insensitive, so
 * `Admin.Example.com` and `admin.example.com` are one host and must not become two operations.
 *
 * @internal
 */
final class RouteHost
{
    public static function of(Route $route): ?string
    {
        $domain = $route->getDomain();

        return $domain === null || $domain === '' ? null : strtolower($domain);
    }
}
