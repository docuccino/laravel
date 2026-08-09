<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Support\AuthGuardDrivers;

/**
 * Dataset coverage for the guard→driver resolution (auth audit #8): every driver kind an auth
 * middleware can resolve to, the bare-`auth` default-guard path, multi-guard lists, and the
 * unknown-guard degradation contract (a guard absent from the map contributes no driver).
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
