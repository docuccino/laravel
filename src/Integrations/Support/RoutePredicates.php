<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Illuminate\Support\Str;

/**
 * The request's route predicates — `routeIs()` and `is()` — answered from the route descriptor alone, the
 * way the framework decides them at run time. The one home for that rule, so no two readers of a route
 * predicate can settle the same route differently.
 */
final class RoutePredicates
{
    /** What Symfony's route compiler reads as a separator in front of a variable (`RouteCompiler::SEPARATORS`). */
    private const SEPARATORS = '/,;.:-_~+*=@|';

    /**
     * `Request::routeIs()` / `Route::named()`: any pattern matching the name, by `Str::is`; an unnamed route
     * matches nothing.
     *
     * @param  list<string>  $patterns
     */
    public static function routeIs(array $patterns, ?string $name): bool
    {
        return $name !== null && Str::is($patterns, $name);
    }

    /**
     * `Request::is()` against every path the route template can take: true where each of them is matched by
     * some pattern, false where every pattern refuses all of them, null where it turns on a parameter's value
     * or on whether an optional one was given.
     *
     * @param  list<string>  $patterns
     */
    public static function pathIs(array $patterns, string $uri): ?bool
    {
        $answers = [];
        foreach (self::forms($uri) as $form) {
            $answers[] = self::anyMatches($patterns, $form);
        }

        $answers = array_values(array_unique($answers, SORT_REGULAR));

        return count($answers) === 1 ? $answers[0] : null;
    }

    /**
     * The template as served with every parameter given, and cut short before each optional one — Symfony's
     * route compiler folds the separator in front of a variable into it, so leaving the parameter out
     * takes that too. A form the route cannot actually take only makes the answer less decided.
     *
     * @return list<string>
     */
    private static function forms(string $uri): array
    {
        $forms = [$uri];
        $offset = 0;
        while (preg_match('/\{[^}]+\?}/', $uri, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $at = $match[0][1];
            $cut = substr($uri, 0, $at);
            if ($cut !== '' && str_contains(self::SEPARATORS, substr($cut, -1))) {
                $cut = substr($cut, 0, -1);
            }
            $forms[] = $cut;
            $offset = $at + strlen($match[0][0]);
        }

        return $forms;
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function anyMatches(array $patterns, string $uri): ?bool
    {
        // The path `is()` reads is the request's, trimmed of its slashes, and `/` for the root.
        $path = trim($uri, '/');
        $path = $path === '' ? '/' : $path;

        $undecided = false;
        foreach ($patterns as $pattern) {
            $matches = self::patternMatches($pattern, $path);
            if ($matches === true) {
                return true;
            }
            $undecided = $undecided || $matches === null;
        }

        return $undecided ? null : false;
    }

    /**
     * One pattern against a template. A template with no parameter is one path, matched exactly; one with
     * parameters is decided only by the text before its first `{` — known to match where the pattern is that
     * prefix plus a trailing `*`, known to refuse where the two part ways.
     */
    private static function patternMatches(string $pattern, string $path): ?bool
    {
        $open = strpos($path, '{');
        if ($open === false) {
            return Str::is($pattern, $path);
        }

        $fixed = substr($path, 0, $open);
        $star = strpos($pattern, '*');
        $literal = $star === false ? $pattern : substr($pattern, 0, $star);

        if ($star !== false && $star === strlen($pattern) - 1 && str_starts_with($fixed, $literal)) {
            return true;
        }

        if (! str_starts_with($fixed, $literal) && ! str_starts_with($literal, $fixed)) {
            return false;
        }

        return null;
    }
}
