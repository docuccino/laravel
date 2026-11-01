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
     * entry is dropped when its resolved name or its class is an excluded resolved name, or when its
     * class is a subclass of an excluded bare class. Both lists must already have their groups
     * expanded, as the framework's do by this point. That stays a caller's contract rather than a check
     * because it cannot be one: without the groups map a group name is indistinguishable from an alias,
     * and taking the map would give this the expansion job the route resolver already owns.
     *
     * What "its class" is changed in Laravel 13.39. Before, it is the whole resolved name, so an entry
     * with arguments is only removed by the same arguments. From 13.39 it is the name without its
     * arguments, so an exclusion naming a middleware bare removes it whatever arguments it carries.
     * `$bareCoversArguments` says which of the two the installed framework does.
     *
     * Matching in the framework's resolved-class space rather than on the literal strings is what makes
     * `withoutMiddleware(Authorize::using('view'))` remove a group's `can:view`, and what keeps
     * `withoutMiddleware('auth:')` from removing a bare `auth` the framework keeps.
     *
     * @param  list<string>  $gathered
     * @param  list<string>  $excluded
     * @param  array<string, string>  $aliases  the router's alias map, alias → class
     * @param  bool  $bareCoversArguments  whether an exclusion with no arguments removes an entry with them
     * @return list<string>
     */
    public static function subtract(array $gathered, array $excluded, array $aliases, bool $bareCoversArguments): array
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
            $class = $bareCoversArguments ? explode(':', $resolved, 2)[0] : $resolved;

            if (in_array($resolved, $resolvedExcluded, true) || in_array($class, $resolvedExcluded, true) || self::subclassOfAny($class, $resolvedExcluded)) {
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
     * @param  bool  $bareCoversArguments  {@see subtract()}
     * @return list<string>
     */
    public static function unmatchedExclusions(array $gathered, array $excluded, array $aliases, bool $bareCoversArguments): array
    {
        $unmatched = [];
        foreach ($excluded as $entry) {
            $name = MiddlewareName::name($entry);

            if (isset($aliases[$name]) || class_exists($name)) {
                continue;
            }

            if (self::subtract($gathered, [$entry], $aliases, $bareCoversArguments) === $gathered && ! in_array($entry, $unmatched, true)) {
                $unmatched[] = $entry;
            }
        }

        return $unmatched;
    }

    /**
     * Whether an entry runs `$class` or a subclass of it: the entry's name resolved through the alias map
     * the way {@see subtract()} resolves it, so a custom alias and an application's own subclass both
     * answer, and an alias the application re-pointed elsewhere does not.
     *
     * @param  class-string  $class
     * @param  array<string, string>  $aliases
     */
    public static function runs(string $entry, string $class, array $aliases): bool
    {
        $resolved = self::className($entry, $aliases);
        $class = MiddlewareName::normalize($class);

        return $resolved === $class || (class_exists($resolved) && is_subclass_of($resolved, $class));
    }

    /**
     * The class an entry's name resolves to through the alias map, arguments dropped — the entry's own
     * name where no alias answers.
     *
     * @param  array<string, string>  $aliases
     */
    public static function className(string $entry, array $aliases): string
    {
        return MiddlewareName::name(self::resolve($entry, $aliases));
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
     * The framework's subclass fallback, gate included: it asks `class_exists()` of both sides first,
     * so neither an excluded name with arguments nor, before 13.39, a gathered one ever reaches it.
     *
     * @param  list<string>  $excluded
     */
    private static function subclassOfAny(string $class, array $excluded): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        foreach ($excluded as $entry) {
            if (class_exists($entry) && is_subclass_of($class, $entry)) {
                return true;
            }
        }

        return false;
    }
}
