<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Sanctum\SanctumDetector;
use Docuccino\Laravel\Integrations\Sanctum\SanctumScheme;

const STATEFUL = 'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful';

it('detects the active Sanctum modes across the detection combinations', function (array $middleware, array $guardDrivers, string $defaultGuard, array $modes): void {
    expect((new SanctumDetector)->supportedModes($middleware, $guardDrivers, $defaultGuard))->toBe($modes);
})->with([
    'token only (auth:sanctum)' => [['auth:sanctum'], [], 'web', ['token']],
    'token only (bare sanctum alias)' => [['sanctum'], [], 'web', ['token']],
    'token only (multi-guard list)' => [['auth:web,sanctum'], [], 'web', ['token']],
    'token only (abilities middleware)' => [['auth:sanctum', 'abilities:read'], [], 'web', ['token']],
    'token only (ability short alias)' => [['ability:read'], [], 'web', ['token']],
    'token only (CheckAbilities ::using FQCN)' => [['Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities:read,write'], [], 'web', ['token']],
    'token only (CheckForAnyAbility ::using FQCN)' => [['Laravel\\Sanctum\\Http\\Middleware\\CheckForAnyAbility:read'], [], 'web', ['token']],
    // Driver-based detection: a custom-named guard whose configured driver is `sanctum`.
    'token only (custom sanctum-driver guard)' => [['auth:mobile'], ['mobile' => 'sanctum'], 'web', ['token']],
    'token only (bare auth, default guard is sanctum)' => [['auth'], ['api' => 'sanctum'], 'api', ['token']],
    'stateful only (cookie SPA, web guard)' => [[STATEFUL, 'auth:web'], [], 'web', ['stateful']],
    'both modes (dual auth on one route)' => [['auth:sanctum', STATEFUL], [], 'web', ['token', 'stateful']],
    // The false-positive guard: statefulApi() prepends the stateful middleware to the whole api
    // group, so a PUBLIC route (no auth guard) carrying only that middleware must yield NO modes.
    'public route (stateful middleware, no auth guard)' => [[STATEFUL], [], 'web', []],
    'public route (stateful middleware + throttle, no auth)' => [[STATEFUL, 'throttle:60,1'], [], 'web', []],
    'neither (plain web auth)' => [['auth:web'], [], 'web', []],
    'neither (api guard, no sanctum)' => [['auth:api'], [], 'web', []],
    // Driver-based: an `api` guard on a token driver is NOT Sanctum token mode.
    'neither (api guard driver is token)' => [['auth:api'], ['api' => 'token'], 'web', []],
    'neither (unknown guard absent from the map)' => [['auth:partner'], [], 'web', []],
]);

it('builds the token and stateful schemes with auth-section prose', function (): void {
    $token = SanctumScheme::token();
    $stateful = SanctumScheme::stateful('laravel_session');

    expect($token['type'])->toBe('http')
        ->and($token['scheme'])->toBe('bearer')
        ->and($token['description'])->toContain('Bearer');

    expect($stateful['type'])->toBe('apiKey')
        ->and($stateful['in'])->toBe('cookie')
        ->and($stateful['name'])->toBe('laravel_session')
        ->and($stateful['description'])->toContain('X-XSRF-TOKEN');
});
