<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Support\Glob;

/**
 * "Is this route behind auth middleware": does any of its middleware match the document's
 * `security.auto_detect_middleware` wildcard (default `auth*`, so `auth:sanctum` and friends count).
 * Shared by the security layer and the implicit-401 synthesis so both key off one signal — which is
 * why reading the wrong spelling of one middleware costs what {@see AuthMiddlewareNames} describes.
 *
 * The pattern is matched against every spelling of each middleware ({@see AuthMiddlewareNames}), not
 * only the string the route carried. A wildcard is written in one vocabulary — the default `auth*` in
 * the alias one — and a route naming the same middleware by class is the same route, so matching the
 * raw string alone made a configured pattern mean different things for `auth:web` and for
 * `Authenticate::using('web')`.
 *
 * The pattern is read with the product's own wildcard grammar ({@see Glob}) rather than `fnmatch()`.
 * `fnmatch()` treats `\` in the PATTERN as an escape character, so a pattern naming a class — the one
 * kind of pattern whose subject is full of backslashes — matched nothing at all unless every separator
 * was doubled.
 */
final class AuthMiddlewareDetector
{
    public static function matches(RouteContext $context): bool
    {
        $pattern = $context->document->authMiddleware;
        if ($pattern === null || $pattern === '') {
            return false;
        }

        foreach ($context->route->middleware as $middleware) {
            foreach (AuthMiddlewareNames::spellings($middleware) as $spelling) {
                if (Glob::matches($pattern, $spelling)) {
                    return true;
                }
            }
        }

        return false;
    }
}
