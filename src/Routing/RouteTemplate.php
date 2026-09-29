<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Illuminate\Routing\Route;
use Symfony\Component\Routing\CompiledRoute;
use Throwable;

/**
 * The one reader of a route template's grammar, read as the router reads it: parameter names as the
 * route binds them, and the optional run and each segment's requirement off the regex the router
 * compiles. A `{param?}` is optional only in the template's trailing run, which is the compiler's rule
 * and not a copy of it, so every reader of a template here agrees with what the router matches.
 *
 * @phpstan-type Token array{text: string, separator: string, requirement: ?string, name: ?string}
 *
 * @internal
 */
final class RouteTemplate
{
    /** @var array<string, list<array{string, string}>> template → its optional run, as [separator, name] */
    private static array $optional = [];

    /**
     * The parameter names of a template (a path or a host), in template order, as the route binds them:
     * the `?` marker is route syntax, not part of the name.
     *
     * @return list<string>
     */
    public static function parameters(string $template): array
    {
        preg_match_all('/\{(.*?)\}/', $template, $matches);

        return array_map(static fn (string $raw): string => trim($raw, '?'), $matches[1]);
    }

    /**
     * The segments of a path template a request may leave off, in template order. One the router cannot
     * compile has none: no request reaches it at all.
     *
     * @return list<string>
     */
    public static function optional(string $uri): array
    {
        return array_column(self::optionalRun($uri), 1);
    }

    /**
     * Every URL form the router answers for a template, longest first: the template itself, then one
     * form per optional segment it can end before, each paired with the segments it leaves off.
     * `posts/{year?}/{month?}` → `posts/{year?}/{month?}`, `posts/{year?}` (without `month`), `posts`
     * (without both). A form keeps the markers of the segments it still holds.
     *
     * @return list<array{uri: string, omitted: list<string>}>
     */
    public static function forms(string $uri): array
    {
        $optional = self::optionalRun($uri);
        $names = array_column($optional, 1);
        $forms = [['uri' => $uri, 'omitted' => []]];

        for ($i = count($optional) - 1; $i >= 0; $i--) {
            [$separator, $name] = $optional[$i];
            $at = (int) strrpos($uri, '{'.$name.'?}');
            $width = strlen($separator);
            $cut = $width > 0 && $at >= $width && substr($uri, $at - $width, $width) === $separator ? $at - $width : $at;

            $forms[] = ['uri' => substr($uri, 0, $cut), 'omitted' => array_slice($names, $i)];
        }

        return $forms;
    }

    /**
     * The path the router matches for one form of a route, as tokens in template order: literal `text`,
     * or a segment with its `separator` and the `requirement` the router compiled for it — the route's
     * constraint, or the default the compiler derives from the separators around it. Null where the
     * router cannot compile the route.
     *
     * @param  list<string>  $omitted  the trailing segments the form leaves off
     * @return list<Token>|null
     */
    public static function tokens(Route $route, array $omitted = []): ?array
    {
        $tokens = self::compiledTokens($route);
        if ($tokens === null) {
            return null;
        }

        $tokens = array_map(
            static fn (array $token): array => ['text' => $token['text'], 'separator' => $token['separator'], 'requirement' => $token['requirement'], 'name' => $token['name']],
            $tokens,
        );

        return array_slice($tokens, 0, count($tokens) - count($omitted));
    }

    /** The requirement the router compiled for one path segment of a route, or null where it has none. */
    public static function requirement(Route $route, string $name): ?string
    {
        foreach (self::tokens($route) ?? [] as $token) {
            if ($token['name'] === $name) {
                return $token['requirement'];
            }
        }

        return null;
    }

    /**
     * The regex the router matches a request's path against, and the literal prefix every path it
     * matches starts with; null where the router cannot compile the route.
     *
     * @return array{regex: string, prefix: string}|null
     */
    public static function pathRegex(Route $route): ?array
    {
        $compiled = self::compile($route);

        return $compiled === null ? null : ['regex' => $compiled->getRegex(), 'prefix' => $compiled->getStaticPrefix()];
    }

    /** The regex the router matches a route's host against, or null when it answers on every host. */
    public static function hostRegex(Route $route): ?string
    {
        return self::compile($route)?->getHostRegex();
    }

    /**
     * The trailing segments the compiler makes optional, as [separator, name]: segments with a default —
     * which the route gives each `{param?}` — with nothing but such segments after them.
     *
     * @return list<array{string, string}>
     */
    private static function optionalRun(string $uri): array
    {
        if (isset(self::$optional[$uri])) {
            return self::$optional[$uri];
        }

        $probe = new Route(['GET'], $uri, static fn (): null => null);
        $defaults = $probe->getOptionalParameterNames();

        $run = [];
        // An "important" segment (`{!name}`) is never optional.
        foreach (array_reverse(self::compiledTokens($probe) ?? []) as $token) {
            if ($token['name'] === null || $token['important'] || ! array_key_exists($token['name'], $defaults)) {
                break;
            }

            $run[] = [$token['separator'], $token['name']];
        }

        return self::$optional[$uri] = array_reverse($run);
    }

    /**
     * The compiled path's tokens in template order — the compiler lists them last-first.
     *
     * @return list<array{text: string, separator: string, requirement: ?string, name: ?string, important: bool}>|null
     */
    private static function compiledTokens(Route $route): ?array
    {
        $compiled = self::compile($route);
        if ($compiled === null) {
            return null;
        }

        $tokens = [];
        foreach (array_reverse($compiled->getTokens()) as $token) {
            if (! is_array($token)) {
                return null;
            }

            $part = static fn (int $at): string => is_string($token[$at] ?? null) ? $token[$at] : '';
            $tokens[] = ($token[0] ?? null) === 'variable'
                ? ['text' => '', 'separator' => $part(1), 'requirement' => $part(2), 'name' => $part(3), 'important' => isset($token[5]) && $token[5] === true]
                : ['text' => $part(1), 'separator' => '', 'requirement' => null, 'name' => null, 'important' => false];
        }

        return $tokens;
    }

    private static function compile(Route $route): ?CompiledRoute
    {
        try {
            return $route->toSymfonyRoute()->compile();
        } catch (Throwable) {
            return null;
        }
    }
}
