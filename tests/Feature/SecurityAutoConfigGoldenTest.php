<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * Golden demonstration of Sanctum/Passport security auto-config with audience segmentation via
 * documents (design §Phase 4, coordinator direction): the SAME dual-auth + scoped routes emit a
 * `public` document that lists only the Sanctum bearer token (external consumers never learn cookie
 * auth exists) and an `internal` document that lists both Sanctum modes as an OR-list. Passport's
 * oauth2 scheme + per-operation scopes appear in both.
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    /** @var Router $router */
    $router = app('router');
    $router->get('api/secure/dual', [FormController::class, 'index'])
        ->middleware(['auth:sanctum', 'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful']);
    $router->get('api/secure/scoped', [FormController::class, 'index'])
        ->middleware(['auth:api', 'scopes:read,write']);

    config()->set('docuccino.documents', [
        'public' => [
            'info' => ['title' => 'Public API', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/secure/*']],
            'integrations' => ['sanctum' => ['modes' => ['token']]],
        ],
        'internal' => [
            'info' => ['title' => 'Internal API', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/secure/*']],
        ],
    ]);
});

function securedGolden(string $key, string $file): void
{
    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.'.$key);
    $config = app(DocumentConfigFactory::class)->make($key, $raw, 'skeleton');
    $document = app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document->toArray();

    $emitted = (new UirEmitter)->emit(UirDocument::fromArray($document));
    $path = dirname(__DIR__).'/Fixtures/golden/'.$file;
    if (getenv('DOCUCCINO_UPDATE_GOLDEN') === '1') {
        file_put_contents($path, $emitted);
    }

    expect($emitted)->toBe(file_get_contents($path));
}

it('emits the public (token-only) document byte-identical to its golden', function (): void {
    securedGolden('public', 'workbench-public.uir.json');
});

it('emits the internal (dual-mode) document byte-identical to its golden', function (): void {
    securedGolden('internal', 'workbench-internal.uir.json');
});

it('lists only the bearer token publicly but both modes internally', function (): void {
    /** @var array<string, mixed> $publicRaw */
    $publicRaw = config('docuccino.documents.public');
    $public = app(DocumentGenerator::class)->generate(
        app(DocumentConfigFactory::class)->make('public', $publicRaw, 'skeleton'),
        app(TypeEngine::class),
    )->document->toArray();

    /** @var array<string, mixed> $internalRaw */
    $internalRaw = config('docuccino.documents.internal');
    $internal = app(DocumentGenerator::class)->generate(
        app(DocumentConfigFactory::class)->make('internal', $internalRaw, 'skeleton'),
        app(TypeEngine::class),
    )->document->toArray();

    expect($public['components']['securitySchemes'])->not->toHaveKey('sanctumStateful')
        ->and($public['paths']['/api/secure/dual']['get']['security'])->toBe([['sanctumToken' => []]]);

    expect($internal['components']['securitySchemes'])->toHaveKeys(['sanctumToken', 'sanctumStateful'])
        ->and($internal['paths']['/api/secure/dual']['get']['security'])->toBe([['sanctumToken' => []], ['sanctumStateful' => []]])
        ->and($internal['paths']['/api/secure/scoped']['get']['security'])->toBe([['passport' => ['read', 'write']]]);
});
