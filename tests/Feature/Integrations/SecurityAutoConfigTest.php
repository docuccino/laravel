<?php

declare(strict_types=1);

use Docuccino\Laravel\Tests\Fixtures\Middleware\ApplicationAuthenticate;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * Real-path coverage for Sanctum/Passport security auto-config (design §Phase 4): the extensions read
 * the actual gathered route middleware through the pipeline, register the scheme into
 * components.securitySchemes, and set the per-operation security requirement. Explicit config schemes
 * suppress auto-config (config wins).
 */
function autoConfiguredDocument(): array
{
    bindStubEngine();

    return generateDocument()->document->toArray();
}

const SANCTUM_STATEFUL = 'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful';

it('auto-configures a token-only bearer scheme for a plain auth:sanctum route', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/sanctum-tokens', [FormController::class, 'index'])->middleware('auth:sanctum');

    $document = autoConfiguredDocument();

    expect($document['components']['securitySchemes']['sanctumToken']['type'])->toBe('http')
        ->and($document['components']['securitySchemes']['sanctumToken']['scheme'])->toBe('bearer')
        ->and($document['paths']['/api/sanctum-tokens']['get']['security'])->toBe([['sanctumToken' => []]]);
});

it('emits an OR-list of both schemes for a dual-auth (token + stateful) route', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/sanctum-dual', [FormController::class, 'index'])->middleware(['auth:sanctum', SANCTUM_STATEFUL]);

    $document = autoConfiguredDocument();

    expect($document['components']['securitySchemes'])->toHaveKeys(['sanctumToken', 'sanctumStateful'])
        ->and($document['components']['securitySchemes']['sanctumStateful']['in'])->toBe('cookie')
        ->and($document['paths']['/api/sanctum-dual']['get']['security'])->toBe([
            ['sanctumToken' => []],
            ['sanctumStateful' => []],
        ]);
});

it('detects group-prepended stateful middleware (statefulApi) through the expanded route middleware', function (): void {
    /** @var Router $router */
    $router = app('router');
    // Simulate statefulApi() prepending EnsureFrontendRequestsAreStateful to an app-wide group: the
    // route carries only the GROUP name, not the FQCN. The resolver must expand the group so the
    // stateful mode is detected the same as if declared inline.
    $router->middlewareGroup('docuccino-stateful-api', [SANCTUM_STATEFUL]);
    $router->get('api/sanctum-grouped', [FormController::class, 'index'])->middleware(['auth:sanctum', 'docuccino-stateful-api']);

    $document = autoConfiguredDocument();

    expect($document['components']['securitySchemes'])->toHaveKeys(['sanctumToken', 'sanctumStateful'])
        ->and($document['paths']['/api/sanctum-grouped']['get']['security'])->toBe([
            ['sanctumToken' => []],
            ['sanctumStateful' => []],
        ]);
});

it('filters modes per document: a token-only document drops the stateful mode from a dual route', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/sanctum-public', [FormController::class, 'index'])->middleware(['auth:sanctum', SANCTUM_STATEFUL]);

    bindStubEngine();
    $document = generateDocument(function (array $raw): array {
        $raw['integrations']['sanctum']['modes'] = ['token'];

        return $raw;
    })->document->toArray();

    expect($document['components']['securitySchemes'])->toHaveKey('sanctumToken')
        ->and($document['components']['securitySchemes'])->not->toHaveKey('sanctumStateful')
        ->and($document['paths']['/api/sanctum-public']['get']['security'])->toBe([['sanctumToken' => []]]);
});

it('auto-configures a Passport oauth2 scheme with per-operation scopes from scope middleware', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/passport-reports', [FormController::class, 'index'])->middleware(['auth:api', 'scopes:read,write']);

    $document = autoConfiguredDocument();

    expect($document['components']['securitySchemes']['passport']['type'])->toBe('oauth2')
        ->and($document['paths']['/api/passport-reports']['get']['security'])->toBe([['passport' => ['read', 'write']]]);
});

it('defers entirely to explicit config security schemes', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/configured-security', [FormController::class, 'index'])->middleware('auth:sanctum');

    bindStubEngine();
    $document = generateDocument(function (array $raw): array {
        $raw['security']['schemes'] = ['bearer' => ['type' => 'http', 'scheme' => 'bearer']];

        return $raw;
    })->document->toArray();

    // The auto-config schemes are not added; only the explicitly-configured one is present.
    expect($document['components']['securitySchemes'])->toHaveKey('bearer')
        ->and($document['components']['securitySchemes'])->not->toHaveKey('sanctumToken')
        ->and($document['components']['securitySchemes'])->not->toHaveKey('sanctumStateful');
});

/**
 * The stateful scheme and the 401 are one fact: a route behind `EnsureFrontendRequestsAreStateful` accepts
 * the SPA's session cookie exactly where an authenticator runs to read it, and that authenticator is what
 * refuses a request without one. So every spelling that documents the 401 owes the cookie requirement,
 * including an application's own alias for its own subclass of `Authenticate`, which reads the session
 * through the parent's `authenticate()`.
 */
it('requires the stateful cookie wherever the stateful middleware runs beside an authenticator', function (string $authenticator): void {
    /** @var Router $router */
    $router = app('router');
    $router->aliasMiddleware('signed-in', ApplicationAuthenticate::class);
    $router->aliasMiddleware('fw-signed-in', Authenticate::class);
    $router->get('api/stateful-authenticated', [FormController::class, 'index'])->middleware([SANCTUM_STATEFUL, $authenticator]);

    bindStubEngine();
    $operation = generateDocument(function (array $raw): array {
        $raw['error_responses'] = true;

        return $raw;
    })->document->toArray()['paths']['/api/stateful-authenticated']['get'];

    expect($operation['responses'])->toHaveKey('401')
        ->and($operation['security'] ?? null)->toBe([['sanctumStateful' => []]]);
})->with([
    'the auth alias' => ['auth'],
    'the auth alias naming a guard' => ['auth:web'],
    'the class spelling Authenticate::using() writes' => [Authenticate::using('web')],
    'basic auth' => ['auth.basic'],
    'basic auth by class' => [AuthenticateWithBasicAuth::class],
    'an application alias of the framework authenticator' => ['fw-signed-in'],
    'an application alias of its own subclass' => ['signed-in'],
    'the application subclass by class' => [ApplicationAuthenticate::using('web')],
]);

it('leaves a stateful route public where its alias resolves to something that does not authenticate', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->aliasMiddleware('signed-in', ThrottleRequests::class);
    $router->get('api/stateful-throttled', [FormController::class, 'index'])->middleware([SANCTUM_STATEFUL, 'signed-in:60,1']);

    bindStubEngine();
    $operation = generateDocument()->document->toArray()['paths']['/api/stateful-throttled']['get'];

    expect($operation)->not->toHaveKey('security');
});

/**
 * A guard is named by the authenticator's parameters, whatever the alias the application wrote it under:
 * `signed-in:sanctum` runs `Authenticate` against the `sanctum` guard exactly as `auth:sanctum` does, and
 * refuses a request without a token with the same 401. So each spelling owes the token requirement.
 */
it('requires the Sanctum token wherever the authenticator names the sanctum guard', function (string $authenticator): void {
    /** @var Router $router */
    $router = app('router');
    $router->aliasMiddleware('signed-in', ApplicationAuthenticate::class);
    $router->aliasMiddleware('fw-signed-in', Authenticate::class);
    $router->get('api/token-authenticated', [FormController::class, 'index'])->middleware($authenticator);

    $operation = autoConfiguredDocument()['paths']['/api/token-authenticated']['get'];

    expect($operation['security'] ?? null)->toBe([['sanctumToken' => []]]);
})->with([
    'the auth alias' => ['auth:sanctum'],
    'the class spelling Authenticate::using() writes' => [Authenticate::using('sanctum')],
    'the application subclass by class' => [ApplicationAuthenticate::using('sanctum')],
    'an application alias of the framework authenticator' => ['fw-signed-in:sanctum'],
    'an application alias of its own subclass' => ['signed-in:sanctum'],
]);

it('names no guard where the alias resolves to something that does not authenticate', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->aliasMiddleware('signed-in', ThrottleRequests::class);
    $router->get('api/throttled-guard', [FormController::class, 'index'])->middleware('signed-in:sanctum');

    expect(autoConfiguredDocument()['paths']['/api/throttled-guard']['get'])->not->toHaveKey('security');
});
