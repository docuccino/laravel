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
