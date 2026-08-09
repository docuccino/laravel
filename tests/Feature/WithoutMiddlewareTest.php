<?php

declare(strict_types=1);

use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Routing\LaravelRouteResolver;
use Workbench\App\Http\Controllers\FormController;

/**
 * `withoutMiddleware(...)` exclusions (arch/qa §1.2): the route resolver mirrors Laravel's own
 * Router — an excluded middleware is removed from the gathered set — so a route that opts out of
 * `throttle`/`auth` is not documented with the 429/401 (or the security requirement) it never
 * enforces at runtime. Regression guard for the historically-ignored `$route->excludedMiddleware()`.
 */
beforeEach(function (): void {
    bindStubEngine();

    $router = app('router');
    // Rate-limited but the throttle is explicitly excluded → no 429 should be documented.
    $router->get('api/opt-out-throttle', [FormController::class, 'index'])
        ->middleware('throttle:60,1')
        ->withoutMiddleware('throttle:60,1');
    // Authenticated but the auth guard is explicitly excluded → no 401 and no security requirement.
    $router->get('api/opt-out-auth', [FormController::class, 'index'])
        ->middleware('auth:web')
        ->withoutMiddleware('auth:web');
    $router->getRoutes()->refreshNameLookups();
});

it('drops excluded middleware from the resolved route descriptor', function (): void {
    $document = app(DocumentConfigFactory::class)
        ->make('default', (array) config('docuccino.documents.default'), 'skeleton');

    $middlewareByUri = [];
    foreach (app(LaravelRouteResolver::class)->resolve($document) as $descriptor) {
        $middlewareByUri[$descriptor->uri] = $descriptor->middleware;
    }

    expect($middlewareByUri['/api/opt-out-throttle'] ?? null)->not->toContain('throttle:60,1')
        ->and($middlewareByUri['/api/opt-out-auth'] ?? null)->not->toContain('auth:web');
});

it('documents no 429 for a route that excludes its throttle middleware', function (): void {
    $document = generateDocument()->document->toArray();

    $responses = $document['paths']['/api/opt-out-throttle']['get']['responses'] ?? [];
    expect($responses)->not->toHaveKey('429');
});

it('documents no 401 and no security for a route that excludes its auth middleware', function (): void {
    $document = generateDocument()->document->toArray();

    $operation = $document['paths']['/api/opt-out-auth']['get'] ?? [];
    expect($operation['responses'] ?? [])->not->toHaveKey('401')
        ->and($operation)->not->toHaveKey('security');
});
