<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Extensions\Context\RouteContext;

/**
 * The one predicate for "this route is behind authentication middleware": any of the route's
 * middleware matches the document's `security.auto_detect_middleware` wildcard (default `auth*`,
 * matching `auth`, `auth:sanctum`, `auth:web`, …). Shared by the security layer (which applies the
 * document's default requirement) and the implicit-response layer (which synthesizes a 401), so both
 * key off the same signal.
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
            if (fnmatch($pattern, $middleware)) {
                return true;
            }
        }

        return false;
    }
}
