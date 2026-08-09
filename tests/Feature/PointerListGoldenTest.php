<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * A byte-locked golden for the RFC 9457 Problem Details preset with the `pointer-list` errors shape —
 * the exact configuration a large production Laravel app runs with
 * (`error_responses => ['preset' => 'problem-details',
 * 'errors_shape' => 'pointer-list']`). Guards the pointer-list 422 body (`errors` as a list of
 * `{detail, pointer}` objects) against silent drift end-to-end, not just at the unit level.
 */
it('emits a pointer-list problem-details document byte-identical to its committed golden', function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $raw['info'] = ['title' => 'Pointer-list API', 'version' => '1.0.0'];
    $raw['error_responses'] = ['preset' => 'problem-details', 'errors_shape' => 'pointer-list'];

    $config = app(DocumentConfigFactory::class)->make('pointer-list', $raw, 'skeleton');
    $emitted = (new UirEmitter)->emit(app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document);

    $path = dirname(__DIR__).'/Fixtures/golden/workbench-pointer-list.uir.json';
    if (getenv('DOCUCCINO_UPDATE_GOLDEN') === '1') {
        file_put_contents($path, $emitted);
    }

    expect($emitted)->toBe(file_get_contents($path))
        // The pointer-list 422 shape is present: an `errors` array of {detail, pointer} objects.
        ->and($emitted)->toContain('pointer');
});
