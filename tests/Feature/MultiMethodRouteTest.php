<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * A route registered for several verbs documents one operation per method (arch F8): PUT|PATCH
 * yields two operations sharing a path, each with its own operation identity.
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
});

function buildMultiMethod(): array
{
    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $config = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');

    return app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document->toArray();
}

it('documents every verb of a match() route as its own operation', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->match(['put', 'patch'], 'api/forms/{form}/status', [FormController::class, 'show']);

    $paths = buildMultiMethod()['paths'];

    expect($paths['/api/forms/{form}/status'])->toHaveKeys(['put', 'patch']);

    $putId = $paths['/api/forms/{form}/status']['put']['x-docuccino']['id'] ?? null;
    $patchId = $paths['/api/forms/{form}/status']['patch']['x-docuccino']['id'] ?? null;

    // Distinct operations carry distinct identities (identity folds in the method).
    expect($putId)->not->toBeNull()
        ->and($patchId)->not->toBeNull()
        ->and($putId)->not->toBe($patchId);
});
