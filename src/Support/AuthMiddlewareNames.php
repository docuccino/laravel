<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * The framework's authentication middleware, in every spelling a route can name it: the registered
 * alias, or the middleware's own class name — which is what `Authenticate::using('web')` renders and
 * what a route listing `AuthenticateSession::class` writes.
 *
 * Whether a route is authenticated is the one fact that decides both the implicit 401 and the security
 * requirement, and it has readers on both sides of the Extensions/Integrations line. A reader that
 * knows only the alias therefore publishes an authenticated route as PUBLIC — no 401, no scheme, and a
 * generated client that omits the credential. That consequence is stated here, once, and pointed at
 * from the readers.
 *
 * Every question about the family is a function of {@see spellings()}, so no two of them can disagree
 * about the same string. The grammar over it is {@see MiddlewareName}. An application's own
 * authenticator counts as one of the family through its parent class, which is the one thing here
 * that reads more than the string it was given.
 */
final class AuthMiddlewareNames
{
    /**
     * Alias => class for the framework's own `auth*` aliases (`Middleware::defaultAliases()`). The two
     * halves of each row are two spellings of ONE middleware, which is the whole reason this is a map
     * and not a list.
     */
    private const FAMILY = [
        'auth' => 'Illuminate\\Auth\\Middleware\\Authenticate',
        'auth.basic' => 'Illuminate\\Auth\\Middleware\\AuthenticateWithBasicAuth',
        'auth.session' => 'Illuminate\\Session\\Middleware\\AuthenticateSession',
    ];

    /**
     * Both spellings of one middleware entry — the alias and the class name, arguments preserved. An
     * entry outside the family is its own only spelling.
     *
     * This is what keeps a configured wildcard meaning the same thing whichever spelling a route used —
     * the pattern is matched against the whole set rather than against the one string the route happened
     * to carry, so `auth*` and a hand-narrowed `auth:api` both answer identically for `auth:api` and for
     * `Authenticate::using('api')`.
     *
     * An empty argument list is not the same string as a bare name, and the two spellings of each are
     * kept apart: `auth:` answers `['auth:', 'Illuminate\Auth\Middleware\Authenticate:']` and never
     * claims the bare `auth`. The framework agrees — it compares resolved strings with their arguments
     * attached, so `Authenticate` and `Authenticate:` are two middleware to it.
     *
     * @return list<string>
     */
    public static function spellings(string $middleware): array
    {
        foreach (self::FAMILY as $alias => $class) {
            $arguments = MiddlewareName::arguments($middleware, $alias, $class);
            if ($arguments === null) {
                continue;
            }

            $suffix = MiddlewareName::bare($middleware) ? '' : ':'.$arguments;

            return [$alias.$suffix, $class.$suffix];
        }

        return self::subclassSpellings($middleware);
    }

    /**
     * The same two spellings for an application's OWN authenticator: a class extending one of the
     * family authenticates exactly the way its parent does — the ≤10 skeleton's
     * `app/Http/Middleware/Authenticate.php` overrides nothing but `redirectTo()` — so the alias half
     * is the parent's alias and the class half is the class the route actually wrote.
     *
     * Read off the class hierarchy rather than off the router's alias map, because the map is EMPTY in
     * the context the product runs in ({@see MiddlewareAliases}) while the hierarchy answers the same
     * in every context, aliased or not. The family classes are unrelated to each other, so at most one
     * of them answers.
     *
     * @return list<string>
     */
    private static function subclassSpellings(string $middleware): array
    {
        $class = MiddlewareName::name($middleware);
        $arguments = explode(':', $middleware, 2)[1] ?? null;
        $suffix = $arguments === null ? '' : ':'.$arguments;

        if (class_exists($class)) {
            foreach (self::FAMILY as $alias => $parent) {
                if (is_subclass_of($class, $parent)) {
                    return [$alias.$suffix, $class.$suffix];
                }
            }
        }

        return [$middleware];
    }

    /**
     * Whether an entry names authentication middleware at all: the `auth` alias with any arguments, any
     * `auth.<variant>` alias — the convention the framework's own `auth.basic` and `auth.session` follow,
     * and which an application's own guard variant follows too — or the class name of one of the family,
     * bare or with arguments.
     *
     * Asked of the spellings rather than of the entry, because {@see spellings()} is where the
     * class-name half is already turned into the alias one: a predicate that read the family a second
     * way could answer differently from the set it is supposed to describe.
     */
    public static function matches(string $middleware): bool
    {
        foreach (self::spellings($middleware) as $spelling) {
            if ($spelling === 'auth'
                || str_starts_with($spelling, 'auth:')
                || str_starts_with($spelling, 'auth.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The arguments of the guard-selecting authenticator — the `auth` alias or `Authenticate` itself —
     * unsplit: the empty string where the entry selects no guard BY NAME (a bare `auth`, or the empty
     * argument list `auth:`), and null for anything else. Which guard an empty list means is the
     * caller's question, not this one's — the guard→driver resolution answers it the way
     * `AuthManager::guard()` does.
     *
     * The `auth.basic`/`auth.session` variants take arguments of their own but are deliberately not
     * read here: they are not how a route names a guard.
     */
    public static function guardArguments(string $middleware): ?string
    {
        foreach (self::spellings($middleware) as $spelling) {
            $arguments = MiddlewareName::arguments($spelling, 'auth');
            if ($arguments !== null) {
                return $arguments;
            }
        }

        return null;
    }
}
