<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Docuccino\Core\Support\PortablePattern;
use Illuminate\Routing\Route;

/**
 * The regular expression each path segment must match before the router will answer at all —
 * `->where()`, its `whereUuid()`-style shorthands, and the `Route::pattern()`s merged in when the route
 * was registered — read as the router reads it. The route's compiler strips one leading `^`/`\A` and one
 * trailing `$`/`\z` and embeds the rest in the whole path's regex, so the expression returned here is the
 * one that regex holds. Both the descriptor's cache key and the route context read it from here.
 *
 * @internal
 */
final class RouteConstraints
{
    /**
     * Segment name → expression, for the segments of the path template that carry one. A global
     * pattern is in every route's `wheres`, so a name the path does not use is dropped: it constrains
     * nothing here, and keying on it would retire this route's fragment for a pattern it never reads.
     *
     * @return array<string, string>
     */
    public static function of(Route $route): array
    {
        preg_match_all('/\{([^}]+)}/', $route->uri(), $matches);
        $segments = array_map(static fn (string $raw): string => rtrim($raw, '?'), $matches[1]);

        $constraints = [];
        foreach ($route->wheres as $name => $expression) {
            if (is_string($name) && is_string($expression) && in_array($name, $segments, true)) {
                $constraints[$name] = self::unanchored($expression);
            }
        }
        ksort($constraints);

        return $constraints;
    }

    /**
     * The same constraints as cache-key inputs: adding `->whereUuid()` to a route changes its document
     * and moves nothing else the key holds.
     *
     * @return list<string>
     */
    public static function cacheInputs(Route $route): array
    {
        $inputs = [];
        foreach (self::of($route) as $name => $expression) {
            $inputs[] = 'where:'.$name.'='.$expression;
        }

        return $inputs;
    }

    /**
     * The anchored `pattern` accepting what the router matches for one constraint, or null when none says
     * it truly. The router compiles the path under `sDu` — `u` making `\d` and `\w` every script's — and
     * embeds the expression in it, so an inner `^` or `$` anchors the PATH; one it cannot compile is no
     * route at all, never a pattern.
     */
    public static function pattern(string $expression): ?string
    {
        if (@preg_match('{^'.$expression.'$}sDu', '') === false) {
            return null;
        }

        return PortablePattern::anchored($expression, unicode: true, anchors: false);
    }

    /** The anchors the route compiler removes before embedding the expression, removed the same way. */
    private static function unanchored(string $expression): string
    {
        if (str_starts_with($expression, '^')) {
            $expression = substr($expression, 1);
        } elseif (str_starts_with($expression, '\\A')) {
            $expression = substr($expression, 2);
        }

        if (str_ends_with($expression, '$')) {
            return substr($expression, 0, -1);
        }

        // Only when the FIRST `\z` is the last, exactly as the compiler tests for it.
        return strpos($expression, '\\z') === strlen($expression) - 2 ? substr($expression, 0, -2) : $expression;
    }
}
