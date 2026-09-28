<?php

declare(strict_types=1);

use Docuccino\Laravel\Support\MiddlewareResolution;
use Docuccino\Laravel\Tests\Fixtures\Middleware\ApplicationAuthenticate;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Workbench\App\Http\Middleware\ValidateLinkSignature;

/**
 * The framework's own middleware resolution, read as a function of the ALIAS MAP — which is the input
 * both halves were missing. The rows below are stated from `Router::resolveMiddleware()`'s rule (resolve
 * both sides through the map, drop an exact match, then the subclass fallback behind its `class_exists`
 * gate) rather than from this package's answer; `WithoutMiddlewareTest` holds the same claim against the
 * real Router, so a row here that agrees with the code and not with the framework fails there.
 *
 * The two maps are the two an application really has: the framework's default, and the ≤10 skeleton's
 * `auth` bound to the application's own `Authenticate` subclass.
 */
$framework = ['auth' => Authenticate::class, 'can' => Authorize::class, 'throttle' => ThrottleRequests::class];
$skeleton = ['auth' => ApplicationAuthenticate::class, 'can' => Authorize::class];

it('keeps what the framework keeps and drops what it drops', function (array $gathered, array $excluded, array $aliases, array $kept): void {
    expect(MiddlewareResolution::subtract($gathered, $excluded, $aliases))->toBe($kept);
})->with([
    'nothing excluded keeps everything' => [['auth:web', 'throttle:60,1'], [], $framework, ['auth:web', 'throttle:60,1']],
    'the same spelling both sides' => [['auth:web'], ['auth:web'], $framework, []],
    // Two spellings of one middleware: the framework resolves both to one class and drops it.
    'the alias excluded by the class name' => [['auth:web'], [Authenticate::class.':web'], $framework, []],
    'the class name excluded by the alias' => [[Authenticate::class.':web'], ['auth:web'], $framework, []],
    'the authorization middleware, the same way' => [['can:view'], [Authorize::using('view')], $framework, []],
    'the rate limiter, the same way' => [['throttle:60,1'], [ThrottleRequests::class.':60,1'], $framework, []],
    // An exclusion removes what it names and nothing else.
    'one exclusion out of two middleware' => [['auth:web', 'throttle:60,1'], ['throttle:60,1'], $framework, ['auth:web']],
    'an exclusion that names nothing on the route' => [['auth:web'], ['throttle:60,1'], $framework, ['auth:web']],
    // Arguments are part of the resolved name, so these pairs are two middleware to the framework.
    'a bare name is not an empty argument list' => [['auth'], ['auth:'], $framework, ['auth']],
    'an empty argument list is not a bare name' => [['auth:'], ['auth'], $framework, ['auth:']],
    'different arguments are different middleware' => [['auth:web'], ['auth:api'], $framework, ['auth:web']],
    // The subclass fallback, and its `class_exists` gate: it can only ever fire for two BARE class
    // names, which is why the framework keeps the argumented pair below.
    'a bare subclass excluded by its bare parent' => [[ApplicationAuthenticate::class], [Authenticate::class], $framework, []],
    'an argumented subclass is not reached by the fallback' => [[ApplicationAuthenticate::class.':web'], [Authenticate::class.':web'], $framework, [ApplicationAuthenticate::class.':web']],
    'a parent is not a subclass of its own child' => [[Authenticate::class], [ApplicationAuthenticate::class], $framework, [Authenticate::class]],
    // And the map that decides all of it. Under the skeleton's alias, `auth:web` IS the application's
    // subclass and is NOT the framework's authenticator — both directions, and the second is the one
    // that drops a 401 the server enforces.
    'the skeleton alias, excluded by the class it points at' => [['auth:web'], [ApplicationAuthenticate::class.':web'], $skeleton, []],
    'the skeleton alias, excluded by the framework class it does not point at' => [['auth:web'], [Authenticate::class.':web'], $skeleton, ['auth:web']],
]);

/**
 * Which class an entry RUNS, stated from `MiddlewareNameResolver::resolve()`: the name is looked up in
 * the alias map first and taken as a class name otherwise, and the arguments are never part of the
 * class. A subclass runs its parent's behaviour, which is what a reader asking by class wants to hear.
 */
it('answers which middleware class an entry runs', function (string $entry, array $aliases, bool $runs): void {
    expect(MiddlewareResolution::runs($entry, ValidateSignature::class, $aliases))->toBe($runs);
})->with([
    'the framework alias' => ['signed', ['signed' => ValidateSignature::class], true],
    'the framework alias with arguments' => ['signed:relative,utm_source', ['signed' => ValidateSignature::class], true],
    'the class, from its own constructor' => [ValidateSignature::relative(), [], true],
    'the class, absolute with ignored parameters' => [ValidateSignature::absolute(['utm_source']), [], true],
    'the class with a leading separator' => ['\\'.ValidateSignature::class, [], true],
    "an application's subclass, from the inherited constructor" => [ValidateLinkSignature::relative(), [], true],
    "an application's own alias" => ['signed.link', ['signed.link' => ValidateSignature::class], true],
    "an application's own alias for its subclass" => ['signed.app:relative', ['signed.app' => ValidateLinkSignature::class], true],
    // The map is the application's to change, in both directions.
    'the framework alias re-pointed elsewhere' => ['signed', ['signed' => ThrottleRequests::class], false],
    'an alias the map does not hold' => ['signed', [], false],
    'an unrelated middleware' => ['throttle:60,1', ['throttle' => ThrottleRequests::class], false],
    'a class that does not exist' => ['App\\Http\\Middleware\\ValidateSignature', [], false],
    'a name the alias is only a prefix of' => ['signedIn', ['signed' => ValidateSignature::class], false],
]);
