<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\AdminController;

/**
 * Multiple documents (design §Multiple documents): independent pipeline runs sharing route
 * contexts, the #[InDocs] cross-check, and export-all as the default. Two documents are declared —
 * `default` (api/*) and `admin` (api/admin/*) — with an admin route pinned to "admin".
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    /** @var Router $router */
    $router = app('router');
    $router->get('api/admin/panel', [AdminController::class, 'panel']);

    config()->set('docuccino.documents', [
        'default' => [
            'info' => ['title' => 'API Documentation', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/*']],
        ],
        'admin' => [
            'info' => ['title' => 'Admin API', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/admin/*']],
        ],
    ]);
});

/**
 * @return array<string, mixed>
 */
function buildDocument(string $key): array
{
    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.'.$key);
    $config = app(DocumentConfigFactory::class)->make($key, $raw, 'skeleton');

    return app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document->toArray();
}

it('cross-checks #[InDocs]: the admin route appears only in the admin document', function (): void {
    $default = buildDocument('default');
    $admin = buildDocument('admin');

    // The admin route matches the default include (api/*) but #[InDocs('admin')] keeps it out...
    expect($default['paths'])->not->toHaveKey('/api/admin/panel')
        ->and($default['paths'])->toHaveKey('/api/forms')
        // ...and the admin document contains only its own route.
        ->and(array_keys($admin['paths']))->toBe(['/api/admin/panel']);
});

it('produces the admin document byte-identical to its committed golden', function (): void {
    $admin = buildDocument('admin');

    $emitted = (new UirEmitter)->emit(UirDocument::fromArray($admin));

    $path = dirname(__DIR__).'/Fixtures/golden/workbench-admin.uir.json';
    if (getenv('DOCUCCINO_UPDATE_GOLDEN') === '1') {
        file_put_contents($path, $emitted);
    }

    expect($emitted)->toBe(file_get_contents($path));
});

it('exports every document by default (export-all)', function (): void {
    $defaultOut = sys_get_temp_dir().'/docuccino-default-'.uniqid().'.json';
    $adminOut = sys_get_temp_dir().'/docuccino-admin-'.uniqid().'.json';

    config()->set('docuccino.documents.default.export.path', $defaultOut);
    config()->set('docuccino.documents.admin.export.path', $adminOut);

    // No document argument → every document is exported to its own configured path.
    $this->artisan('docuccino:export')->assertSuccessful();

    expect(file_exists($defaultOut))->toBeTrue()
        ->and(file_exists($adminOut))->toBeTrue()
        ->and(file_get_contents($defaultOut))->toContain('/api/forms')
        ->and(file_get_contents($adminOut))->toContain('/api/admin/panel')
        ->and(file_get_contents($adminOut))->not->toContain('/api/forms');

    @unlink($defaultOut);
    @unlink($adminOut);
});
