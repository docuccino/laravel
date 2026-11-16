<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Laravel\Passport\Passport;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Workbench\App\Http\Controllers\FormController;

/**
 * Routes guarded by an auth package's own token middleware, under byte-lock in a document that publishes
 * its error responses: Sanctum's ability middleware and Passport's scope and client-credentials middleware,
 * each on its own and beside `auth:sanctum`, under the aliases an application registers for them. No other
 * committed document carries both these routes and error responses.
 */
beforeEach(function (): void {
    refreshWithoutHttpKernel();
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    Passport::tokensCan(['read' => 'Read data', 'write' => 'Write data']);

    // Passport ships no aliases; these are the names its documentation registers, for the major installed.
    $passport13 = class_exists('Laravel\\Passport\\Http\\Middleware\\CheckToken');
    registerAppMiddlewareAliases([
        'abilities' => CheckAbilities::class,
        'ability' => CheckForAnyAbility::class,
        'scopes' => $passport13 ? 'Laravel\\Passport\\Http\\Middleware\\CheckToken' : 'Laravel\\Passport\\Http\\Middleware\\CheckScopes',
        'scope' => $passport13 ? 'Laravel\\Passport\\Http\\Middleware\\CheckTokenForAnyScope' : 'Laravel\\Passport\\Http\\Middleware\\CheckForAnyScope',
        'client' => $passport13 ? 'Laravel\\Passport\\Http\\Middleware\\EnsureClientIsResourceOwner' : 'Laravel\\Passport\\Http\\Middleware\\CheckClientCredentials',
    ]);

    /** @var Router $router */
    $router = app('router');
    $router->get('api/tokens/reports', [FormController::class, 'index'])->middleware('abilities:read');
    $router->get('api/tokens/any-report', [FormController::class, 'index'])->middleware('ability:read,write');
    $router->get('api/tokens/signed-in-reports', [FormController::class, 'index'])->middleware(['auth:sanctum', 'abilities:read']);
    $router->get('api/tokens/orders', [FormController::class, 'index'])->middleware('scopes:read,write');
    $router->get('api/tokens/any-order', [FormController::class, 'index'])->middleware('scope:read,write');
    $router->get('api/tokens/machine', [FormController::class, 'index'])->middleware('client:read');
    $router->get('api/tokens/any-machine', [FormController::class, 'index'])->middleware('client');
    $router->get('api/tokens/public', [FormController::class, 'index']);
    $router->getRoutes()->refreshNameLookups();

    setDocuments([
        'tokens' => [
            'info' => ['title' => 'Token Middleware', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/tokens/*']],
            'error_responses' => 'default',
        ],
    ]);
});

/** @return array<string, mixed> */
function tokenMiddlewareDocument(): array
{
    /** @var array<string, mixed> $raw */
    $raw = documentSettings('tokens');
    $config = app(DocumentConfigFactory::class)->make('tokens', $raw, 'skeleton');

    return app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document->toArray();
}

it('emits the token-middleware routes byte-identical to the golden', function (): void {
    assertGolden('workbench-token-middleware.uir.json', (new UirEmitter)->emit(UirDocument::fromArray(tokenMiddlewareDocument())));
});

/** @return list<string> */
function tokenMiddlewareStatuses(string $path): array
{
    return array_map(strval(...), array_keys(tokenMiddlewareDocument()['paths'][$path]['get']['responses']));
}

/**
 * Each of these middleware reads the token itself and throws `AuthenticationException` at a request
 * without one, so the route answers 401 though it runs no `auth:*`.
 */
it('publishes the 401 on a route behind token middleware alone', function (string $path): void {
    expect(tokenMiddlewareStatuses($path))->toContain('401');
})->with([
    'abilities:' => '/api/tokens/reports',
    'ability:' => '/api/tokens/any-report',
    'scopes:' => '/api/tokens/orders',
    'scope:' => '/api/tokens/any-order',
    'client:' => '/api/tokens/machine',
    'bare client' => '/api/tokens/any-machine',
]);

/**
 * A token lacking a scope or ability the entry names is refused with the package's `AuthorizationException`
 * subclass, which Laravel renders as a 403. An entry naming none checks the token and nothing past it.
 */
it('publishes the 403 on a route whose token middleware names a scope or ability', function (string $path): void {
    expect(tokenMiddlewareStatuses($path))->toContain('403');
})->with([
    'abilities:' => '/api/tokens/reports',
    'ability:' => '/api/tokens/any-report',
    'abilities: beside auth:sanctum' => '/api/tokens/signed-in-reports',
    'scopes:' => '/api/tokens/orders',
    'scope:' => '/api/tokens/any-order',
    'client:' => '/api/tokens/machine',
]);

it('publishes neither where nothing checks a scope or a token', function (): void {
    expect(tokenMiddlewareStatuses('/api/tokens/any-machine'))->not->toContain('403')
        ->and(tokenMiddlewareStatuses('/api/tokens/public'))->toBe(['200']);
});
