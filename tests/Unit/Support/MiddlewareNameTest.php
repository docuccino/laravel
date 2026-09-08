<?php

declare(strict_types=1);

use Docuccino\Laravel\Support\MiddlewareName;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Routing\Middleware\ValidateSignature;

/**
 * The two spellings Laravel writes a middleware in. The last test is the one that matters: it takes
 * the strings from the framework's own static constructors rather than typing them out, because a
 * reader held against a string this suite invented is not reading the grammar it guards.
 */
it('reads a middleware under either spelling, with or without arguments', function (string $middleware, ?string $arguments): void {
    expect(MiddlewareName::arguments($middleware, 'signed', ValidateSignature::class))->toBe($arguments)
        ->and(MiddlewareName::matches($middleware, 'signed', ValidateSignature::class))->toBe($arguments !== null);
})->with([
    'the alias, bare' => ['signed', ''],
    'the alias with one argument' => ['signed:relative', 'relative'],
    'the alias with several' => ['signed:relative,page', 'relative,page'],
    'the alias with a trailing colon' => ['signed:', ''],
    'the class name, bare' => ['Illuminate\\Routing\\Middleware\\ValidateSignature', ''],
    'the class name with arguments' => ['Illuminate\\Routing\\Middleware\\ValidateSignature:relative', 'relative'],
    'a different middleware' => ['verified', null],
    'a name this one is only a prefix of' => ['signedIn', null],
    'a class whose name this one is only a prefix of' => ['Illuminate\\Routing\\Middleware\\ValidateSignatureLoosely', null],
    'a name that only ends the same way' => ['app.signed', null],
]);

/**
 * A leading `\` is not part of a class name — `Foo::class` never renders one and a hand-written string
 * often does — so it is trimmed off both sides. A route naming the authenticator that way ran it and
 * was published as public.
 */
it('reads a class name written with a leading separator', function (): void {
    expect(MiddlewareName::arguments('\\Illuminate\\Routing\\Middleware\\ValidateSignature:relative', 'signed', ValidateSignature::class))->toBe('relative')
        ->and(MiddlewareName::arguments('\\Illuminate\\Routing\\Middleware\\ValidateSignature', 'signed', ValidateSignature::class))->toBe('')
        // …and on the NAME side too, since a caller may hold its list either way.
        ->and(MiddlewareName::arguments('Illuminate\\Routing\\Middleware\\ValidateSignature:relative', 'signed', '\\'.ValidateSignature::class))->toBe('relative')
        // Case is deliberately not folded: see the class docblock.
        ->and(MiddlewareName::arguments('illuminate\\routing\\middleware\\validatesignature', 'signed', ValidateSignature::class))->toBeNull();
});

/**
 * Bareness, which is the one fact {@see MiddlewareName::arguments()} cannot report: it answers `''` for
 * a bare name AND for an empty argument list, and the framework treats those as two different
 * middleware — it compares its resolved names with the arguments still attached, so `Authenticate` and
 * `Authenticate:` never match each other.
 */
it('tells a bare name from an empty argument list', function (string $middleware, bool $bare): void {
    expect(MiddlewareName::bare($middleware))->toBe($bare);
})->with([
    'the alias, bare' => ['signed', true],
    'the alias with an empty argument list' => ['signed:', false],
    'the alias with an argument' => ['signed:relative', false],
    'the class name, bare' => ['Illuminate\\Routing\\Middleware\\ValidateSignature', true],
    'the class name with an empty argument list' => ['Illuminate\\Routing\\Middleware\\ValidateSignature:', false],
    'a middleware this reader knows nothing about' => ['tenant', true],
]);

it('answers for the first of several names that matches', function (): void {
    expect(MiddlewareName::arguments('can:view,App\\Widget', 'signed', 'can'))->toBe('view,App\\Widget')
        ->and(MiddlewareName::arguments('nothing:here', 'signed', 'can'))->toBeNull()
        // No names at all is no match, rather than a match on everything.
        ->and(MiddlewareName::arguments('signed'))->toBeNull();
});

it('reads what the framework\'s own static constructors write', function (): void {
    // Each of these renders `static::class` plus the arguments, and a route written that way carried no
    // middleware at all as far as a reader of the alias was concerned.
    expect(MiddlewareName::matches(ValidateSignature::relative(), 'signed', ValidateSignature::class))->toBeTrue()
        ->and(MiddlewareName::matches(ValidateSignature::absolute(), 'signed', ValidateSignature::class))->toBeTrue()
        ->and(MiddlewareName::matches(ValidateSignature::absolute('page'), 'signed', ValidateSignature::class))->toBeTrue()
        ->and(MiddlewareName::matches(EnsureEmailIsVerified::redirectTo('verification.notice'), 'verified', EnsureEmailIsVerified::class))->toBeTrue()
        ->and(MiddlewareName::matches(Authorize::using('view', 'widget'), 'can', Authorize::class))->toBeTrue()
        // Anti-vacuity: the reader is not simply saying yes. Each of these is a real middleware string
        // and none of them is the one being asked about.
        ->and(MiddlewareName::matches(ValidateSignature::relative(), 'verified', EnsureEmailIsVerified::class))->toBeFalse()
        ->and(MiddlewareName::matches(Authorize::using('view', 'widget'), 'signed', ValidateSignature::class))->toBeFalse();
});

/**
 * A group reattaches its members' parameters on truthiness, where a route does it on `! is_null()`.
 * Stated from `MiddlewareNameResolver::parseMiddlewareGroup()` rather than from this reader's answer,
 * and held against the real Router by the group rows in `WithoutMiddlewareTest`.
 */
it('reattaches a group member\'s parameters the way a group does', function (string $member, string $expanded): void {
    expect(MiddlewareName::asGroupMember($member))->toBe($expanded);
})->with([
    'a bare name is untouched' => ['auth', 'auth'],
    'an ordinary argument is kept' => ['auth:web', 'auth:web'],
    'several are kept' => ['throttle:60,1', 'throttle:60,1'],
    // The two falsy parameter strings, which a route keeps and a group does not.
    'an empty argument list is dropped' => ['auth:', 'auth'],
    'a zero argument is dropped' => ['auth:0', 'auth'],
    // Only the FIRST separator splits the name, so a zero later in the list is still an argument.
    'a zero among others is kept' => ['throttle:0,1', 'throttle:0,1'],
    'a class name is a name like any other' => ['Illuminate\\Routing\\Middleware\\ThrottleRequests:0', 'Illuminate\\Routing\\Middleware\\ThrottleRequests'],
]);

/** A leading `\` is not part of a class name, so it comes off once for every reader rather than in each. */
it('normalizes a class name written with a leading separator', function (): void {
    expect(MiddlewareName::normalize('\\'.ValidateSignature::class.':relative'))->toBe(ValidateSignature::class.':relative')
        ->and(MiddlewareName::normalize(ValidateSignature::class))->toBe(ValidateSignature::class)
        ->and(MiddlewareName::normalize('signed:relative'))->toBe('signed:relative');
});
