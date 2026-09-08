<?php

declare(strict_types=1);

use Docuccino\Laravel\Support\AuthMiddlewareNames;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * The authentication middleware family and the spellings a route can name it by. Every row is asserted
 * in BOTH spellings against the same expectation, because "the alias and the class name are two
 * spellings of one thing" is the whole claim — asserting each separately would pass on a reader that
 * answers them differently.
 */
it('answers every spelling of the authenticator identically', function (string $alias, string $class): void {
    expect(AuthMiddlewareNames::spellings($alias))->toContain($alias, $class)
        ->and(AuthMiddlewareNames::spellings($class))->toContain($alias, $class)
        ->and(AuthMiddlewareNames::matches($alias))->toBeTrue()
        ->and(AuthMiddlewareNames::matches($class))->toBeTrue()
        // Arguments travel with the name, so a pattern sees the same tail whichever spelling was used.
        ->and(AuthMiddlewareNames::spellings($alias.':a,b'))->toContain($alias.':a,b', $class.':a,b')
        ->and(AuthMiddlewareNames::spellings($class.':a,b'))->toContain($alias.':a,b', $class.':a,b')
        ->and(AuthMiddlewareNames::matches($class.':a,b'))->toBeTrue();
})->with([
    'auth' => ['auth', Authenticate::class],
    'auth.basic' => ['auth.basic', AuthenticateWithBasicAuth::class],
    'auth.session' => ['auth.session', AuthenticateSession::class],
]);

it('reads the guard arguments of the guard-selecting authenticator in either spelling', function (string $middleware, ?string $arguments): void {
    expect(AuthMiddlewareNames::guardArguments($middleware))->toBe($arguments);
})->with([
    'bare alias' => ['auth', ''],
    'bare class' => [Authenticate::class, ''],
    'alias with one guard' => ['auth:web', 'web'],
    'class with one guard' => [Authenticate::class.':web', 'web'],
    '::using() renders the class spelling' => [Authenticate::using('web'), 'web'],
    'alias with a guard list' => ['auth:web,sanctum', 'web,sanctum'],
    '::using() with a guard list' => [Authenticate::using('web', 'sanctum'), 'web,sanctum'],
    'empty argument list' => ['auth:', ''],
    // The variants take arguments of their own, but none of them is a guard the route is selecting.
    'auth.basic names no guard' => ['auth.basic:web,email', null],
    'AuthenticateWithBasicAuth names no guard' => [AuthenticateWithBasicAuth::class.':web', null],
    'auth.session names no guard' => ['auth.session', null],
    'an unrelated alias' => ['throttle:60,1', null],
    'an unrelated class' => ['App\\Http\\Middleware\\Tenant:acme', null],
    // Prefix-only lookalikes: a name is a whole name, never a prefix of one.
    'a longer alias that starts with auth' => ['authorize:view', null],
    'a longer class that starts with the authenticator' => [Authenticate::class.'Session', null],
]);

/**
 * An empty argument list is its own name. `MiddlewareName::arguments()` answers `''` for `auth` and for
 * `auth:` alike, so a reader reconstructing the name from the arguments alone claimed the bare `auth`
 * as a spelling of `auth:` — and the framework does not agree: it compares its resolved names with the
 * arguments attached, so `Authenticate` and `Authenticate:` are two middleware to it. Through a
 * subtraction that equivalence removed a 401 the server does enforce.
 */
it('never claims a bare name as a spelling of an empty argument list', function (string $alias, string $class): void {
    expect(AuthMiddlewareNames::spellings($alias.':'))->toBe([$alias.':', $class.':'])
        ->and(AuthMiddlewareNames::spellings($class.':'))->toBe([$alias.':', $class.':'])
        ->and(AuthMiddlewareNames::spellings($alias))->toBe([$alias, $class])
        ->and(AuthMiddlewareNames::spellings($class))->toBe([$alias, $class])
        // Stated as the disjointness it is, so a reader that reintroduces the collision fails here.
        ->and(array_intersect(AuthMiddlewareNames::spellings($alias.':'), AuthMiddlewareNames::spellings($alias)))->toBe([]);
})->with([
    'auth' => ['auth', Authenticate::class],
    'auth.basic' => ['auth.basic', AuthenticateWithBasicAuth::class],
    'auth.session' => ['auth.session', AuthenticateSession::class],
]);

/**
 * A leading `\` names the same class — `Foo::class` never renders one, a hand-written middleware string
 * often does — so the entry is read as the family member it is rather than as a middleware nobody
 * recognises, which published the route as public.
 */
it('reads a class spelling written with a leading separator', function (): void {
    expect(AuthMiddlewareNames::spellings('\\'.Authenticate::class.':web'))->toBe(['auth:web', Authenticate::class.':web'])
        ->and(AuthMiddlewareNames::matches('\\'.Authenticate::class))->toBeTrue()
        ->and(AuthMiddlewareNames::guardArguments('\\'.Authenticate::class.':web'))->toBe('web');
});

it('leaves a middleware outside the family as its own only spelling', function (string $middleware): void {
    expect(AuthMiddlewareNames::spellings($middleware))->toBe([$middleware])
        ->and(AuthMiddlewareNames::matches($middleware))->toBeFalse();
})->with([
    'a rate limiter' => ['throttle:60,1'],
    'the authorization middleware' => ['can:view,App\\Widget'],
    'an application middleware' => ['App\\Http\\Middleware\\Tenant'],
    // `authorize` shares four letters with `auth` and is a different middleware.
    'an alias that merely starts with auth' => ['authorize:view'],
    'a class that merely starts with the authenticator' => [Authenticate::class.'Session'],
]);

/**
 * An application's own guard variant follows the framework's `auth.<variant>` convention, so the family
 * predicate recognises the shape as well as the three rows — the behaviour the Sanctum stateful gate has
 * always had, kept here rather than re-stated there.
 */
it('recognises an application auth.<variant> alias by the convention', function (): void {
    expect(AuthMiddlewareNames::matches('auth.otp'))->toBeTrue()
        ->and(AuthMiddlewareNames::matches('auth.otp:sms'))->toBeTrue()
        // …but the convention is only an alias convention: it says nothing about a guard.
        ->and(AuthMiddlewareNames::guardArguments('auth.otp:sms'))->toBeNull()
        ->and(AuthMiddlewareNames::spellings('auth.otp:sms'))->toBe(['auth.otp:sms']);
});

/**
 * The hand-maintained family read against its SOURCE OF TRUTH: the framework's own default alias map.
 * A dataset only proves the rows it lists, and an `auth*` alias the framework adds — or renames — would
 * otherwise leave this list short with the whole suite green, which is exactly how a route spelled by
 * class name came to be published as public.
 */
it('lists every authentication alias the framework registers', function (): void {
    $aliases = (new Middleware)->getMiddlewareAliases();

    $authentication = array_filter(
        $aliases,
        static fn (string $alias): bool => $alias === 'auth' || str_starts_with($alias, 'auth.'),
        ARRAY_FILTER_USE_KEY,
    );

    // A scan that stopped recognising its shapes must fail rather than pass: the framework has shipped
    // `auth`, `auth.basic` and `auth.session` for its whole 5.x–12.x history.
    expect(count($authentication))->toBeGreaterThanOrEqual(3);

    foreach ($authentication as $alias => $class) {
        expect(AuthMiddlewareNames::spellings($alias))->toContain(ltrim($class, '\\'), $alias)
            ->and(AuthMiddlewareNames::matches(ltrim($class, '\\')))->toBeTrue();
    }
});
