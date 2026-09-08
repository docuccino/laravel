<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Support\NameList;
use Docuccino\Core\Support\PlainText;
use Illuminate\Routing\Router;
use ReflectionClass;
use Throwable;

/**
 * The alias → class map a route's middleware is resolved through: the framework's own default aliases,
 * with whatever the application registered on the router laid over them. Which two strings name ONE
 * middleware is this map's answer, and an application's map is its own — it may register `auth`
 * against its OWN `Authenticate` subclass, as the Laravel ≤10 skeleton does and every application
 * upgraded from one still carries, and then `auth:web` and `App\Http\Middleware\Authenticate:web` are
 * one middleware while `auth:web` and the framework's `Authenticate:web` are two.
 *
 * The router's map is EMPTY until the HTTP kernel is constructed — that is what calls
 * `syncMiddlewareToRouter()` — so a build has the router filled before reading it
 * ({@see MiddlewareRegistrations}). The framework's default table underneath is what survives a fill
 * that could not be performed, and it can only widen the answer:
 * `Middleware::getMiddlewareAliases()` is `array_merge(defaultAliases(), $customAliases)`, so a real
 * map is always a superset of the defaults — the fallback can neither introduce an alias the framework
 * would not have had nor resurrect one the application replaced.
 *
 * What it cannot stand in for is an alias of the application's OWN, which is invisible wherever that
 * fill failed. Where that costs a published fact this says so ({@see unmatchedExclusion()}).
 *
 * The map reaches the published document only through the middleware list the route resolver hands on,
 * and that list is folded into {@see RouteDescriptor::cacheSignature()} verbatim — so a map edited in a
 * service provider invalidates exactly the fragments whose middleware it changed, and needs no
 * environment digest of its own.
 */
final class MiddlewareAliases
{
    /**
     * `Illuminate\Foundation` ships only in `laravel/framework`, which has no split package to depend
     * on, so the table is named by string. Read rather than copied, so it is a function of the version
     * the application resolved.
     */
    private const string CONFIGURATION = 'Illuminate\\Foundation\\Configuration\\Middleware';

    /** The one method of it read, named once because the diagnostic quotes it. */
    private const string TABLE = 'getMiddlewareAliases';

    /**
     * `$report` hears about a table that could not be read at all — the one case where the answer is
     * narrower than the framework's own defaults rather than wider. `$table` is the class that holds
     * the defaults, an input rather than a constant because which grammar it has is a function of the
     * version the application resolved.
     *
     * @param  ?callable(Diagnostic): void  $report
     * @return array<string, string>
     */
    public static function of(Router $router, ?callable $report = null, string $table = self::CONFIGURATION): array
    {
        $aliases = [];
        foreach ([...self::frameworkDefaults($table, $report), ...$router->getMiddleware()] as $alias => $class) {
            if (is_string($alias) && is_string($class)) {
                $aliases[$alias] = $class;
            }
        }

        return $aliases;
    }

    /**
     * An exclusion this build cannot vouch for: `withoutMiddleware()` naming something that removed
     * nothing and that the map could not resolve, so either the route never carried it or it is
     * another spelling of a middleware the route does carry and the responses behind it are documented
     * without being enforced.
     *
     * @param  list<string>  $entries
     */
    public static function unmatchedExclusion(string $routeSignature, array $entries): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'route.unmatched-exclusion',
            message: sprintf(
                'Route excludes %s, which removed no middleware and names no alias this build could resolve. '
                .'Either the route never carried it — check the spelling — or it is another spelling of one the route '
                .'does carry, and the responses behind that middleware are documented but not enforced. Writing both '
                .'sides in the same spelling settles it.',
                (string) NameList::of($entries),
            ),
            routeSignature: $routeSignature,
        );
    }

    /**
     * @param  ?callable(Diagnostic): void  $report
     * @return array<array-key, mixed>
     */
    private static function frameworkDefaults(string $configuration, ?callable $report): array
    {
        if (! class_exists($configuration)) {
            // No `Illuminate\Foundation` at all: an application without it has no default aliases to
            // find, so there is nothing to report either.
            return [];
        }

        // Read through reflection rather than called outright, because the grammar is a function of the
        // installed major: a method it renamed, removed or made protected — or a constructor it gave a
        // required argument — is an `Error`, and an uncaught one here takes the whole build with it.
        $reason = 'the installed framework does not carry it';

        try {
            $reflection = new ReflectionClass($configuration);

            if ($reflection->hasMethod(self::TABLE)) {
                $method = $reflection->getMethod(self::TABLE);
                $reason = 'it is not a public instance method there';

                if ($method->isPublic() && ! $method->isStatic()) {
                    $aliases = $method->invoke($reflection->newInstance());
                    $reason = 'it answered with something that is not a map';

                    if (is_array($aliases)) {
                        return $aliases;
                    }
                }
            }
        } catch (Throwable $failure) {
            $reason = PlainText::of($failure->getMessage());
        }

        if ($report !== null) {
            $report(self::unreadableDefaults($configuration, $reason));
        }

        return [];
    }

    /** The framework's table is present and still could not be read: the answer degrades to whatever the router holds. */
    private static function unreadableDefaults(string $configuration, string $reason): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'route.middleware-aliases-unreadable',
            message: sprintf(
                'Could not read the framework\'s default middleware aliases (%s): %s. Middleware is resolved through '
                .'whatever aliases the router itself holds — nothing at all where the HTTP kernel could not be resolved '
                .'either, so a middleware named by its class is no longer equated with its alias, and a route that opts '
                .'out of one in the other spelling keeps the response it does not enforce.',
                $configuration.'::'.self::TABLE.'()',
                $reason,
            ),
        );
    }
}
