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
use Workbench\App\Http\Controllers\AuthAttributesController;
use Workbench\App\Http\Controllers\FormController;

/**
 * Byte-locked demonstration of the Wave-D auth additions end-to-end through the real pipeline
 * (auth #5, #7, #8, #9): Sanctum token abilities (`x-abilities`), Passport client-credentials and
 * driver-based guard detection, and the `#[OptionallyAuthenticated]` attribute layering an anonymous
 * alternative over the inferred Sanctum token. `#[Security]`'s explicit OR-list is proven separately
 * (it references config-declared schemes, which suppress auto-config), so it is not in this document.
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    // A realistic Passport app defines its scope catalogue, so every Passport route shares one
    // oauth2 scheme (rather than fragmenting when scopes are only ad-hoc per route).
    Passport::tokensCan(['read' => 'Read data', 'write' => 'Write data']);

    // A custom-named guard whose driver is passport — recognised by driver, not name.
    config()->set('auth.guards', [
        'web' => ['driver' => 'session', 'provider' => 'users'],
        'partner' => ['driver' => 'passport', 'provider' => 'users'],
    ]);

    /** @var Router $router */
    $router = app('router');
    // Optionally-authenticated: anonymous OR the inferred Sanctum token.
    $router->get('api/wave-d/feed', [AuthAttributesController::class, 'feed'])->middleware('auth:sanctum');
    // Sanctum token abilities via middleware (all-of).
    $router->get('api/wave-d/reports', [FormController::class, 'index'])->middleware(['auth:sanctum', 'abilities:read,write']);
    // A body-checked ability declared with #[Abilities].
    $router->post('api/wave-d/publish', [AuthAttributesController::class, 'publish'])->middleware('auth:sanctum');
    // Passport client-credentials (machine-to-machine) with parsed scopes.
    $router->get('api/wave-d/machine', [FormController::class, 'index'])->middleware('client:read,write');
    // A passport-driver guard recognised by its driver, not the name `api`.
    $router->get('api/wave-d/partner', [FormController::class, 'index'])->middleware('auth:partner');

    config()->set('docuccino.documents', [
        'wave-d-auth' => [
            'info' => ['title' => 'Wave D Auth', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/wave-d/*']],
        ],
    ]);
});

afterEach(function (): void {
    // Reset the process-global Passport scope catalogue so it can't leak into other tests.
    Passport::tokensCan([]);
});

it('emits the Wave-D auth document byte-identical to its golden', function (): void {
    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.wave-d-auth');
    $config = app(DocumentConfigFactory::class)->make('wave-d-auth', $raw, 'skeleton');
    $document = app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document->toArray();

    assertGolden('workbench-auth.uir.json', (new UirEmitter)->emit(UirDocument::fromArray($document)));
});
