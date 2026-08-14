<?php

declare(strict_types=1);

use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngineBuilder;
use Docuccino\Laravel\Engine\EnginePackage;
use Docuccino\Laravel\Engine\TypeEngineFactory;
use Docuccino\Laravel\Pipeline\DocumentBuilder;

/**
 * `composer require docuccino/laravel` installs no static analyser, so the adapter has to generate
 * without the engine package — loudly, never silently. The absence is simulated in-process with an
 * injected probe (the integration-toggle precedent) rather than by uninstalling anything.
 */
function engineLessPackage(): EnginePackage
{
    return new EnginePackage(static fn (string $class): bool => false);
}

it('documents from docblocks and attributes with no engine installed, and warns once', function (): void {
    app()->instance(EnginePackage::class, engineLessPackage());

    $result = app(DocumentBuilder::class)->build('default', new NullTypeEngine);

    $warnings = diagnosticsCoded($result->diagnostics, 'engine.not-installed');
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->severity->value)->toBe('warning')
        ->and($warnings[0]->help)->toContain('composer require --dev docuccino/inference-phpstan');

    // The attribute + docblock tiers are untouched: WidgetController::store carries its summary in a
    // docblock, its query parameter and its 201 body (through the core type grammar) in attributes.
    $document = $result->document->toArray();
    $operation = $document['paths']['/api/widgets']['post'] ?? [];
    expect($operation['summary'] ?? null)->toBe('Create a widget.')
        ->and(paramsByName($operation))->toHaveKey('dry_run')
        ->and($operation['responses']['201']['description'] ?? null)->toBe('The created widget.')
        ->and($operation['responses']['201']['content']['application/json']['schema'] ?? [])->not->toBe([]);
});

it('reports engine presence per mode', function (string $mode, string $expected): void {
    config()->set('docuccino.engine.mode', $mode);
    app()->instance(EnginePackage::class, engineLessPackage());

    $result = app(DocumentBuilder::class)->build('default', new NullTypeEngine);

    expect(diagnosticsCoded($result->diagnostics, 'engine.not-installed'))->toHaveCount($expected === 'engine.not-installed' ? 1 : 0)
        ->and(diagnosticsCoded($result->diagnostics, 'engine.mode-unknown'))->toBe([]);
})->with([
    // A missing engine outranks an unknown mode — one diagnostic, not two.
    'in-process' => ['in-process', 'engine.not-installed'],
    'unknown' => ['orchestrated', 'engine.not-installed'],
    // `null` is an explicit opt-out from inference, so nothing is missing.
    'null' => ['null', 'silent'],
]);

it('builds the null engine for every mode when the package is absent', function (string $mode): void {
    $engine = (new TypeEngineFactory(
        basePath: base_path(),
        tmpDir: storage_path('docuccino'),
        engine: engineLessPackage(),
    ))->make(['mode' => $mode, 'project_paths' => ['app']]);

    expect($engine)->toBeInstanceOf(NullTypeEngine::class);
})->with(['null', 'in-process', 'orchestrated']);

it('resolves the engine package entry class when it is installed', function (): void {
    // The BUILDER constant is the whole seam: a typo would disable inference silently and for ever.
    expect(class_exists(EnginePackage::BUILDER))->toBeTrue()
        ->and(is_a(EnginePackage::BUILDER, TypeEngineBuilder::class, true))->toBeTrue()
        ->and((new EnginePackage)->installed())->toBeTrue()
        ->and((new EnginePackage)->builder())->toBeInstanceOf(TypeEngineBuilder::class);
});
