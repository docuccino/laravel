<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Sanctum\SanctumDetector;
use Docuccino\Laravel\Integrations\Sanctum\SanctumScheme;
use Docuccino\Laravel\Support\AuthMiddlewareNames;
use Docuccino\Laravel\Support\RouteAuthentication;
use Illuminate\Auth\Middleware\Authenticate;

const STATEFUL = 'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful';

/**
 * Whether the route runs an authenticator is the adapter's answer ({@see RouteAuthentication}), so the
 * stateful rows here hand it in; which middleware spellings answer it is proved on the published document
 * in SecurityAutoConfigTest. The token rows hand in `false`: a token guard is an authenticator of its own.
 */
it('detects the active Sanctum modes across the detection combinations', function (array $middleware, array $guardDrivers, string $defaultGuard, bool $authenticates, array $modes): void {
    expect((new SanctumDetector)->supportedModes($middleware, $guardDrivers, $defaultGuard, fn (): bool => $authenticates, AuthMiddlewareNames::guardArguments(...)))->toBe($modes);
})->with([
    'token only (auth:sanctum)' => [['auth:sanctum'], [], 'web', false, ['token']],
    'token only (bare sanctum alias)' => [['sanctum'], [], 'web', false, ['token']],
    'token only (multi-guard list)' => [['auth:web,sanctum'], [], 'web', false, ['token']],
    'token only (abilities middleware)' => [['auth:sanctum', 'abilities:read'], [], 'web', false, ['token']],
    'token only (ability short alias)' => [['ability:read'], [], 'web', false, ['token']],
    'token only (CheckAbilities ::using FQCN)' => [['Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities:read,write'], [], 'web', false, ['token']],
    'token only (CheckForAnyAbility ::using FQCN)' => [['Laravel\\Sanctum\\Http\\Middleware\\CheckForAnyAbility:read'], [], 'web', false, ['token']],
    // Driver-based detection: a custom-named guard whose configured driver is `sanctum`.
    'token only (custom sanctum-driver guard)' => [['auth:mobile'], ['mobile' => 'sanctum'], 'web', false, ['token']],
    'token only (bare auth, default guard is sanctum)' => [['auth'], ['api' => 'sanctum'], 'api', false, ['token']],
    'stateful only (cookie SPA, web guard)' => [[STATEFUL, 'auth:web'], [], 'web', true, ['stateful']],
    // The token guard authenticates the session too, whatever the adapter was asked.
    'both modes (dual auth on one route)' => [['auth:sanctum', STATEFUL], [], 'web', false, ['token', 'stateful']],
    // The false-positive guard: statefulApi() prepends the stateful middleware to the whole api
    // group, so a PUBLIC route (no authenticator) carrying only that middleware must yield NO modes.
    'public route (stateful middleware, no authenticator)' => [[STATEFUL], [], 'web', false, []],
    'public route (stateful middleware + throttle, no authenticator)' => [[STATEFUL, 'throttle:60,1'], [], 'web', false, []],
    // An authenticator without the stateful middleware accepts no SPA cookie.
    'neither (plain web auth)' => [['auth:web'], [], 'web', true, []],
    'neither (api guard, no sanctum)' => [['auth:api'], [], 'web', true, []],
    // Driver-based: an `api` guard on a token driver is NOT Sanctum token mode.
    'neither (api guard driver is token)' => [['auth:api'], ['api' => 'token'], 'web', true, []],
    'neither (unknown guard absent from the map)' => [['auth:partner'], [], 'web', true, []],
    // Every token row above that names the guard, again in the spelling `Authenticate::using()` renders.
    'token only (Authenticate::using(sanctum))' => [[Authenticate::using('sanctum')], [], 'web', false, ['token']],
    'token only (Authenticate::using(web,sanctum))' => [[Authenticate::using('web', 'sanctum')], [], 'web', false, ['token']],
    'token only (class-spelled custom sanctum-driver guard)' => [[Authenticate::using('mobile')], ['mobile' => 'sanctum'], 'web', false, ['token']],
    'token only (bare Authenticate, default guard is sanctum)' => [[Authenticate::class], ['api' => 'sanctum'], 'api', false, ['token']],
    'both modes (class-spelled dual auth on one route)' => [[Authenticate::using('sanctum'), STATEFUL], [], 'web', false, ['token', 'stateful']],
    'neither (class-spelled plain web auth)' => [[Authenticate::using('web')], [], 'web', true, []],
    'neither (class-spelled api guard driver is token)' => [[Authenticate::using('api')], ['api' => 'token'], 'web', true, []],
]);

it('asks whether the route authenticates only where the stateful middleware is present', function (): void {
    $asked = 0;
    $authenticates = function () use (&$asked): bool {
        $asked++;

        return true;
    };

    (new SanctumDetector)->supportedModes(['auth:web'], [], 'web', $authenticates, AuthMiddlewareNames::guardArguments(...));
    expect($asked)->toBe(0);

    (new SanctumDetector)->supportedModes([STATEFUL, 'auth:web'], [], 'web', $authenticates, AuthMiddlewareNames::guardArguments(...));
    expect($asked)->toBe(1);
});

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
