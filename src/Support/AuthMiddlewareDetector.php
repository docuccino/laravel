<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Support\Glob;

/**
 * "Is this route behind auth middleware": does any of its middleware match the document's
 * `security.auth_middleware` wildcard (default `auth*`, so `auth:sanctum` and friends count).
 * It decides which routes carry the document's `security.default` requirement, and it is the author's
 * way to name an authenticator of their own, which {@see RouteAuthentication} counts beside the
 * framework's — reading the wrong spelling of one middleware costs what {@see AuthMiddlewareNames}
 * describes.
 *
 * The pattern is matched against every spelling of each middleware ({@see AuthMiddlewareNames}), not
 * only the string the route carried. A wildcard is written in one vocabulary — the default `auth*` in
 * the alias one — and a route naming the same middleware by class is the same route, so matching the
 * raw string alone made a configured pattern mean different things for `auth:web` and for
 * `Authenticate::using('web')`. An application's own alias for an authenticator is that middleware
 * too, so the entry as the alias map resolves it is matched as well ({@see MiddlewareClasses}).
 *
 * The pattern is read with the product's own wildcard grammar ({@see Glob}) rather than `fnmatch()`.
 * `fnmatch()` treats `\` in the PATTERN as an escape character, so a pattern naming a class — the one
 * kind of pattern whose subject is full of backslashes — matched nothing at all unless every separator
 * was doubled.
 */
final class AuthMiddlewareDetector
{
    public static function matches(RouteContext $context, MiddlewareClasses $classes): bool
    {
        foreach ($context->route->middleware as $middleware) {
            if (self::matchesEntry($context, $middleware, $classes)) {
                return true;
            }
        }

        return false;
    }

    /** Whether one of the route's middleware entries matches the pattern, in any of its spellings. */
    public static function matchesEntry(RouteContext $context, string $middleware, MiddlewareClasses $classes): bool
    {
        $pattern = $context->document->authMiddleware;
        if ($pattern === null || $pattern === '') {
            return false;
        }

        $spellings = [
            ...AuthMiddlewareNames::spellings($middleware),
            ...AuthMiddlewareNames::spellings($classes->resolve($context, $middleware)),
        ];
        foreach ($spellings as $spelling) {
            if (Glob::matches($pattern, $spelling)) {
                return true;
            }
        }

        return false;
    }
}
