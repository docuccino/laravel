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
 * one that regex holds. The route's HOST is compiled from the same constraints, so a `{tenant}` in
 * `Route::domain()` is read the same way. Both the descriptor's cache key and the route context read it
 * from here.
 *
 * @internal
 */
final class RouteConstraints
{
    /** Constraints that match any segment at all, which say nothing a plain string does not. */
    public const CATCH_ALL = ['.*', '.+'];

    /** @var array<string, string>|null */
    private static ?array $frameworkFormats = null;

    /**
     * Segment name → expression, for the segments of the path template that carry one. A global
     * pattern is in every route's `wheres`, so a name the path does not use is dropped: it constrains
     * nothing here, and keying on it would retire this route's fragment for a pattern it never reads.
     *
     * @return array<string, string>
     */
    public static function of(Route $route): array
    {
        return self::forTemplate($route, $route->uri());
    }

    /**
     * The same, for the segments of the route's host.
     *
     * @return array<string, string>
     */
    public static function ofHost(Route $route): array
    {
        return self::forTemplate($route, (string) $route->getDomain());
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
        foreach (self::ofHost($route) as $name => $expression) {
            $inputs[] = 'host-where:'.$name.'='.$expression;
        }

        return $inputs;
    }

    /**
     * The format the framework's own `whereUuid()`/`whereUlid()` expression states, or null for any other
     * expression. Read off the framework installed rather than copied, so a release that changes one
     * cannot leave this matching the old.
     */
    public static function format(string $expression): ?string
    {
        if (self::$frameworkFormats === null) {
            $probe = new Route(['GET'], '{segment}', static fn (): null => null);
            $uuid = $probe->whereUuid('segment')->wheres['segment'];
            $ulid = $probe->whereUlid('segment')->wheres['segment'];

            self::$frameworkFormats = is_string($uuid) && is_string($ulid) ? [$uuid => 'uuid', $ulid => 'ulid'] : [];
        }

        return self::$frameworkFormats[$expression] ?? null;
    }

    /** @return array<string, string> */
    private static function forTemplate(Route $route, string $template): array
    {
        $segments = RouteTemplate::parameters($template);

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
