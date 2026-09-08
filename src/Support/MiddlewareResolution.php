<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * A route's middleware set the way the framework's own `Router::resolveMiddleware()` reads it: every
 * entry resolved through the application's alias map before an excluded one is subtracted, and the
 * survivors handed back in the spelling the route wrote them in.
 *
 * The subtraction needs the application's map and was wrong without it — an application may register
 * `auth` against its OWN `Authenticate` subclass, so which two strings name one middleware is the
 * application's fact ({@see MiddlewareAliases}).
 *
 * Pure: the map is passed in, so the resolution is dataset-testable. `class_exists()` here autoloads
 * the same middleware classes the framework's own subtraction does, and only for a route that excludes
 * something.
 */
final class MiddlewareResolution
{
    /**
     * The gathered entries an exclusion does not remove, mirroring `Router::resolveMiddleware()`: an
     * entry is dropped when its resolved name matches an excluded resolved name exactly, or — for a
     * name that is a class with no arguments — when it is a subclass of one. Both lists must already
     * have their groups expanded, as the framework's do by this point. That stays a caller's contract
     * rather than a check because it cannot be one: without the groups map a group name is
     * indistinguishable from an alias, and taking the map would give this the expansion job the route
     * resolver already owns.
     *
     * Matching in the framework's resolved-class space rather than on the literal strings is what makes
     * `withoutMiddleware(Authorize::using('view'))` remove a group's `can:view`, and what keeps
     * `withoutMiddleware('auth:')` from removing a bare `auth` the framework keeps.
     *
     * @param  list<string>  $gathered
     * @param  list<string>  $excluded
     * @param  array<string, string>  $aliases  the router's alias map, alias → class
     * @return list<string>
     */
    public static function subtract(array $gathered, array $excluded, array $aliases): array
    {
        if ($excluded === []) {
            return $gathered;
        }

        $resolvedExcluded = [];
        foreach ($excluded as $entry) {
            $resolvedExcluded[] = self::resolve($entry, $aliases);
        }

        $kept = [];
        foreach ($gathered as $entry) {
            $resolved = self::resolve($entry, $aliases);

            if (in_array($resolved, $resolvedExcluded, true) || self::subclassOfAny($resolved, $resolvedExcluded)) {
                continue;
            }

            $kept[] = $entry;
        }

        return $kept;
    }

    /**
     * The excluded entries that removed nothing AND whose own name this map cannot resolve — the
     * exclusions a build cannot vouch for. Asked by running the subtraction one exclusion at a time,
     * so the answer is a function of the same rule rather than of a second reading of it.
     *
     * An exclusion naming something the route does not carry is ordinary — a group-wide
     * `withoutMiddleware()` where only some members have it — so the unresolvable half is what makes
     * this worth saying: an alias nothing in the map explains may be another spelling of a middleware
     * the route DOES carry, and then the subtraction is short and the document over-describes.
     *
     * @param  list<string>  $gathered
     * @param  list<string>  $excluded
     * @param  array<string, string>  $aliases
     * @return list<string>
     */
    public static function unmatchedExclusions(array $gathered, array $excluded, array $aliases): array
    {
        $unmatched = [];
        foreach ($excluded as $entry) {
            $name = MiddlewareName::name($entry);

            if (isset($aliases[$name]) || class_exists($name)) {
                continue;
            }

            if (self::subtract($gathered, [$entry], $aliases) === $gathered && ! in_array($entry, $unmatched, true)) {
                $unmatched[] = $entry;
            }
        }

        return $unmatched;
    }

    /**
     * `MiddlewareNameResolver::resolve()` for an entry whose groups are already expanded: the alias's
     * class with the arguments reattached, or the entry itself where no alias answers.
     *
     * @param  array<string, string>  $aliases
     */
    private static function resolve(string $entry, array $aliases): string
    {
        $parts = explode(':', $entry, 2);
        $arguments = $parts[1] ?? null;

        return ($aliases[$parts[0]] ?? $parts[0]).($arguments === null ? '' : ':'.$arguments);
    }

    /**
     * The framework's subclass fallback, gate included: it asks `class_exists()` first, so a resolved
     * name carrying arguments never reaches it — which is why `withoutMiddleware(Authenticate::class)`
     * drops a bare subclass entry and leaves an `auth:web` one alone.
     *
     * @param  list<string>  $excluded
     */
    private static function subclassOfAny(string $resolved, array $excluded): bool
    {
        if (! class_exists($resolved)) {
            return false;
        }

        foreach ($excluded as $entry) {
            if (class_exists($entry) && is_subclass_of($resolved, $entry)) {
                return true;
            }
        }

        return false;
    }
}
