<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Extensions\Context\RouteContext;

/**
 * "Is this route behind auth middleware": does any of its middleware match the document's
 * `security.auto_detect_middleware` wildcard (default `auth*`, so `auth:sanctum` and friends count).
 * Shared by the security layer and the implicit-401 synthesis so both key off one signal.
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
