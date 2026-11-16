<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Fixtures\Middleware\ApplicationAuthenticate;
use Docuccino\Laravel\Tests\Fixtures\Middleware\SignedInController;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * The 401 an authenticated route answers a request without credentials, read from the middleware the
 * route runs. Laravel's authenticator refuses that request whatever the document's configuration says,
 * so the 401 is a fact about the route and not about `security.auth_middleware`: that key chooses which
 * routes carry the document's `security.default` requirement, and a document that declares no `security`
 * at all still describes routes the framework refuses.
 *
 * The routes stand for every way an application puts the authenticator in front of an action — an alias
 * with any guard, the class name `Authenticate::using()` renders, the application's own subclass under an
 * alias of its own, a group, and the controller's own middleware — beside routes that run no
 * authenticator, so a document that published a 401 everywhere could not pass.
 */
beforeEach(function (): void {
    refreshWithoutHttpKernel();
    bindStubEngine();

    registerAppMiddlewareAliases(['signed-in' => ApplicationAuthenticate::class]);
    registerAppMiddlewareGroup('signed-in-api', ['auth:sanctum']);

    /** @var Router $router */
    $router = app('router');
    $router->get('api/authn/sanctum', [FormController::class, 'index'])->middleware('auth:sanctum');
    $router->get('api/authn/default-guard', [FormController::class, 'index'])->middleware('auth');
    $router->get('api/authn/api-guard', [FormController::class, 'index'])->middleware('auth:api');
    $router->get('api/authn/web-guard', [FormController::class, 'index'])->middleware('auth:web');
    $router->get('api/authn/by-class', [FormController::class, 'index'])->middleware(Authenticate::using('web'));
    $router->get('api/authn/own-alias', [FormController::class, 'index'])->middleware('signed-in');
    $router->get('api/authn/group', [FormController::class, 'index'])->middleware('signed-in-api');
    $router->get('api/authn/controller', [SignedInController::class, 'index']);
    $router->get('api/authn/basic', [FormController::class, 'index'])->middleware('auth.basic');

    $router->get('api/authn/public', [FormController::class, 'index']);
    $router->get('api/authn/throttled', [FormController::class, 'index'])->middleware('throttle:60,1');
    $router->get('api/authn/excluded', [FormController::class, 'index'])
        ->middleware('signed-in-api')
        ->withoutMiddleware('auth:sanctum');
    // Passes a guest through, and so refuses no request for lacking credentials.
    $router->get('api/authn/session-only', [FormController::class, 'index'])->middleware('auth.session');
    $router->getRoutes()->refreshNameLookups();

    setDocuments([
        // What an application writes when it describes its documents and leaves `security` out.
        'authn' => [
            'info' => ['title' => 'Authenticated routes', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/authn/*']],
            'error_responses' => 'default',
        ],
        // A pattern narrowed to one guard, with a requirement for the routes it matches.
        'authn-narrowed' => [
            'info' => ['title' => 'Authenticated routes', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/authn/*']],
            'error_responses' => 'default',
            'security' => [
                'schemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
                'auth_middleware' => 'auth:api',
                'default' => [['bearer' => []]],
            ],
        ],
    ]);
});

/** @return array<string, mixed> */
function authenticatedRoutesDocument(string $key = 'authn'): array
{
    /** @var array<string, mixed> $raw */
    $raw = documentSettings($key);
    $config = app(DocumentConfigFactory::class)->make($key, $raw, 'skeleton');

    return app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document->toArray();
}

/**
 * @param  array<string, mixed>  $document
 * @return list<string>
 */
function authenticatedRouteStatuses(array $document, string $path): array
{
    return array_map(strval(...), array_keys($document['paths'][$path]['get']['responses']));
}

it('publishes the 401 on every route that runs the authenticator, with no security configured', function (string $path): void {
    expect(authenticatedRouteStatuses(authenticatedRoutesDocument(), $path))->toContain('401');
})->with([
    'auth:sanctum' => '/api/authn/sanctum',
    'auth, the default guard' => '/api/authn/default-guard',
    'auth:api' => '/api/authn/api-guard',
    'the class name Authenticate::using() renders' => '/api/authn/by-class',
    "the application's own subclass under its own alias" => '/api/authn/own-alias',
    'a group carrying auth:sanctum' => '/api/authn/group',
    "the controller's own middleware" => '/api/authn/controller',
    'auth.basic' => '/api/authn/basic',
]);

it('publishes no 401 on a route that runs no authenticator', function (string $path): void {
    expect(authenticatedRouteStatuses(authenticatedRoutesDocument(), $path))->not->toContain('401');
})->with([
    'no middleware' => '/api/authn/public',
    'throttle only' => '/api/authn/throttled',
    'auth:sanctum excluded with withoutMiddleware()' => '/api/authn/excluded',
    'auth.session, which passes a guest through' => '/api/authn/session-only',
]);

/**
 * Laravel renders the refusal to JSON as `{"message": "Unauthenticated."}`, and `auth.basic`'s as
 * `{"message": "Invalid credentials."}`: one object with a required string `message`, shared under the
 * status's component.
 */
it("publishes the framework's JSON body for the 401", function (string $path): void {
    $document = authenticatedRoutesDocument();

    $schema = $document['components']['schemas']['Unauthorized'];

    expect($document['paths'][$path]['get']['responses']['401']['$ref'])->toBe('#/components/responses/Unauthorized')
        ->and($document['components']['responses']['Unauthorized']['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/Unauthorized')
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties']['message']['type'])->toBe('string')
        ->and($schema['required'])->toBe(['message']);
})->with([
    'auth:sanctum' => '/api/authn/sanctum',
    'auth.basic' => '/api/authn/basic',
]);

/**
 * Narrowing the pattern narrows the requirement it applies and nothing else: a route on another guard is
 * still refused by the same authenticator.
 */
it('keeps the 401 on a route the narrowed pattern does not match, without the requirement', function (): void {
    $document = authenticatedRoutesDocument('authn-narrowed');
    $api = $document['paths']['/api/authn/api-guard']['get'];
    $web = $document['paths']['/api/authn/web-guard']['get'];

    expect(authenticatedRouteStatuses($document, '/api/authn/api-guard'))->toContain('401')
        ->and($api['security'])->toBe([['bearer' => []]])
        ->and(authenticatedRouteStatuses($document, '/api/authn/web-guard'))->toContain('401')
        ->and($web)->not->toHaveKey('security');
});
