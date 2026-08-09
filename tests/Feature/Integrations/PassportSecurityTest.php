<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * Real-path coverage for Passport guard-driver detection and client-credentials routes (auth audit
 * #7, #8): the extension reads the actual gathered middleware and the app's `config('auth.guards')`
 * through the pipeline. A `passport`-driver guard (any name, in a multi-guard list) is claimed; an
 * `api` guard on a token driver is not; client-credentials middleware is Passport-protected with its
 * parsed scopes. Passport is a dev dependency, so the integration is registered.
 */
function passportDocument(array $guards): array
{
    config()->set('auth.guards', $guards + ['web' => ['driver' => 'session', 'provider' => 'users']]);

    bindStubEngine();

    return generateDocument()->document->toArray();
}

it('claims a custom passport-driver guard by driver, not name', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/partner-data', [FormController::class, 'index'])->middleware('auth:partner');

    $document = passportDocument(['partner' => ['driver' => 'passport']]);

    expect($document['components']['securitySchemes']['passport']['type'])->toBe('oauth2')
        ->and($document['paths']['/api/partner-data']['get']['security'])->toBe([['passport' => []]]);
});

it('claims a passport-driver guard inside a multi-guard list', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/partner-multi', [FormController::class, 'index'])->middleware('auth:web,partner');

    $document = passportDocument(['partner' => ['driver' => 'passport']]);

    expect($document['paths']['/api/partner-multi']['get']['security'])->toBe([['passport' => []]]);
});

it('does not claim an api guard whose driver is token', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/token-guard', [FormController::class, 'index'])->middleware('auth:api');

    $document = passportDocument(['api' => ['driver' => 'token']]);

    expect($document['components']['securitySchemes'] ?? [])->not->toHaveKey('passport')
        ->and($document['paths']['/api/token-guard']['get'] ?? [])->not->toHaveKey('security');
});

it('documents a client-credentials route with its parsed scopes', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/machine', [FormController::class, 'index'])->middleware('client:read,write');

    $document = passportDocument([]);

    expect($document['components']['securitySchemes']['passport']['flows'])->toHaveKey('clientCredentials')
        ->and($document['paths']['/api/machine']['get']['security'])->toBe([['passport' => ['read', 'write']]]);
});

it('protects a bare client-credentials route with no scopes', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/machine-bare', [FormController::class, 'index'])->middleware('client');

    $document = passportDocument([]);

    expect($document['paths']['/api/machine-bare']['get']['security'])->toBe([['passport' => []]]);
});
