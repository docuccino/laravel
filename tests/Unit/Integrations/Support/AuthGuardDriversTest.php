<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Support\AuthGuardDrivers;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;

/**
 * Dataset coverage for the guard→driver resolution (auth audit #8): every driver kind an auth
 * middleware can resolve to, the bare-`auth` default-guard path, multi-guard lists, and the
 * unknown-guard degradation contract (a guard absent from the map contributes no driver). Each guard
 * spelling is asserted in BOTH forms the framework writes — the alias, and the class name
 * `Authenticate::using()` renders — against the same expectation, because a driver resolved from one and
 * not the other is a route whose owning integration never claims it.
 */
it('resolves auth middleware to the drivers behind their guards', function (array $middleware, array $drivers, string $default, array $expected): void {
    expect(AuthGuardDrivers::driversFor($middleware, $drivers, $default))->toBe($expected);
})->with([
    'auth:api → passport' => [['auth:api'], ['api' => 'passport'], 'web', ['passport']],
    'auth:api → sanctum' => [['auth:api'], ['api' => 'sanctum'], 'web', ['sanctum']],
    'auth:api → token' => [['auth:api'], ['api' => 'token'], 'web', ['token']],
    'auth:web → session' => [['auth:web'], ['web' => 'session'], 'web', ['session']],
    'custom-named passport guard' => [['auth:partner'], ['partner' => 'passport'], 'web', ['passport']],
    'bare auth uses the default guard' => [['auth'], ['api' => 'passport'], 'api', ['passport']],
    'multi-guard list resolves each' => [['auth:web,api'], ['web' => 'session', 'api' => 'passport'], 'web', ['session', 'passport']],
    'drivers deduped in first-seen order' => [['auth:api', 'auth:api2'], ['api' => 'passport', 'api2' => 'passport'], 'web', ['passport']],
    'unknown guard contributes nothing' => [['auth:partner'], [], 'web', []],
    'non-auth middleware contributes nothing' => [['throttle:60,1', 'scopes:read'], ['api' => 'passport'], 'web', []],
    'auth.basic is not a guard driver' => [['auth.basic'], ['web' => 'session'], 'web', []],
    // The class-name spelling, which is what `Authenticate::using()` renders, answers identically.
    'Authenticate::using(api) → passport' => [[Authenticate::using('api')], ['api' => 'passport'], 'web', ['passport']],
    'Authenticate::using(partner) → passport' => [[Authenticate::using('partner')], ['partner' => 'passport'], 'web', ['passport']],
    'bare Authenticate uses the default guard' => [[Authenticate::class], ['api' => 'passport'], 'api', ['passport']],
    'Authenticate::using(web,api) resolves each' => [[Authenticate::using('web', 'api')], ['web' => 'session', 'api' => 'passport'], 'web', ['session', 'passport']],
    'the two spellings dedupe to one driver' => [['auth:api', Authenticate::using('api')], ['api' => 'passport'], 'web', ['passport']],
    'Authenticate::using with an unknown guard contributes nothing' => [[Authenticate::using('partner')], [], 'web', []],
    // An argument list that names no guard names the DEFAULT one, because that is what the framework
    // resolves it to: `AuthManager::guard()` starts `$name = $name ?: $this->getDefaultDriver()`, so
    // `auth:` authenticates against `config('auth.defaults.guard')` exactly as bare `auth` does.
    // Reading it as naming nothing left the 401 in place with no integration claiming the route, so a
    // scheme the server does enforce went unpublished.
    'an empty argument list names the default guard' => [['auth:'], ['web' => 'session'], 'web', ['session']],
    'an empty class-spelled argument list names the default guard' => [[Authenticate::class.':'], ['web' => 'session'], 'web', ['session']],
    'a named guard beside an empty one names both' => [['auth:api,'], ['api' => 'passport', 'web' => 'session'], 'web', ['passport', 'session']],
    // Whitespace is a widening rather than the framework's rule, which is why it is a row of its own:
    // the pipeline splits the parameter list untrimmed, so `auth: ` names the guard `' '`, and
    // `AuthManager::guard(' ')` throws rather than falling back to the default. That route errors at
    // runtime whatever the document says, so what is read is the name it was evidently meant to be.
    'whitespace around a guard name is trimmed off' => [['auth: api'], ['api' => 'passport'], 'web', ['passport']],
    'a guard list of nothing but separators falls back to the default' => [['auth: ,'], ['web' => 'session'], 'web', ['session']],
    'AuthenticateWithBasicAuth is not a guard driver' => [[AuthenticateWithBasicAuth::using('web')], ['web' => 'session'], 'web', []],
]);

it('builds the guard→driver map from raw config, dropping malformed entries', function (): void {
    $guards = [
        'web' => ['driver' => 'session', 'provider' => 'users'],
        'api' => ['driver' => 'passport'],
        'broken' => ['provider' => 'users'],   // no driver
        'notarray' => 'nope',                    // not an array
    ];

    expect(AuthGuardDrivers::map($guards))->toBe(['web' => 'session', 'api' => 'passport']);
    expect(AuthGuardDrivers::map(null))->toBe([]);
    expect(AuthGuardDrivers::map('nope'))->toBe([]);
});
