<?php

declare(strict_types=1);

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
