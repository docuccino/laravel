<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Engine\EnginePackage;
use Docuccino\Laravel\Engine\LazyTypeEngine;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * The engine is installed, the build asked it to analyse, and it could not start — a broken env, a
 * missing bootstrap, an analyser this release does not support. Two things must happen: the document
 * says so, and nothing that build produced is allowed to outlive the breakage in the fragment cache,
 * whose key named the real analyser before the first route was ever built.
 */
afterEach(function (): void {
    removeFragmentCacheDirs('bootfail');
});

it('reports a boot failure once, as an error carrying the underlying words with no machine path in them', function (): void {
    $neon = base_path('vendor/larastan/larastan/extension.neon');
    app()->instance(TypeEngine::class, new LazyTypeEngine(
        static fn (): TypeEngine => new NullTypeEngine('Larastan extension.neon not found at '.$neon),
        EnginePackage::BUILDER,
    ));

    $result = app(DocumentBuilder::class)->build('default', app(TypeEngine::class));

    $errors = diagnosticsCoded($result->diagnostics, 'engine.boot-failed');
    expect($errors)->toHaveCount(1)
        ->and($errors[0]->severity->value)->toBe('error')
        ->and($errors[0]->message)->toContain('vendor/larastan/larastan/extension.neon')
        ->and($errors[0]->message)->not->toContain(base_path())
        ->and($errors[0]->help)->toContain('DOCUCCINO_ENGINE=null')
        // The engine is installed here, so the absent-engine warning has nothing to say.
        ->and(diagnosticsCoded($result->diagnostics, 'engine.not-installed'))->toBe([]);
});

it('says nothing about a boot when nothing woke the engine', function (): void {
    // A build that answers every route from its cache asks no question, so no boot can have failed —
    // and a document with nothing degraded in it must not carry a report of a degradation.
    fragmentCacheDir('bootfail');
    app()->instance(TypeEngine::class, new LazyTypeEngine(
        static fn (): TypeEngine => WorkbenchEngine::make(),
        EnginePackage::BUILDER,
    ));
    $cold = app(DocumentBuilder::class)->build('default', app(TypeEngine::class));

    app()->instance(TypeEngine::class, new LazyTypeEngine(
        static fn (): TypeEngine => new NullTypeEngine('the app would not boot'),
        EnginePackage::BUILDER,
    ));
    $warm = app(DocumentBuilder::class)->build('default', app(TypeEngine::class));

    // What the analysed code says never depended on whether the analyser can run today: yesterday's
    // answers stand, unchanged and unreported.
    expect(diagnosticsCoded($warm->diagnostics, 'engine.boot-failed'))->toBe([])
        ->and((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document));
});

it('never serves a boot-failed build\'s fragments back warm', function (): void {
    fragmentCacheDir('bootfail');

    // The build that breaks: its fingerprint already named the real analyser, so any fragment it
    // stored would be a docblock-only answer filed under an engine that answered nothing.
    app()->instance(TypeEngine::class, new LazyTypeEngine(
        static fn (): TypeEngine => new NullTypeEngine('the app would not boot'),
        EnginePackage::BUILDER,
    ));
    $degraded = (new UirEmitter)->emit(app(DocumentBuilder::class)->build('default', app(TypeEngine::class))->document);

    // Environment fixed, not one analysed file changed.
    app()->instance(TypeEngine::class, new LazyTypeEngine(
        static fn (): TypeEngine => WorkbenchEngine::make(),
        EnginePackage::BUILDER,
    ));
    $warm = (new UirEmitter)->emit(app(DocumentBuilder::class)->build('default', app(TypeEngine::class))->document);

    // The truth: the same build against a store the broken run never touched.
    fragmentCacheDir('bootfail');
    app()->instance(TypeEngine::class, new LazyTypeEngine(
        static fn (): TypeEngine => WorkbenchEngine::make(),
        EnginePackage::BUILDER,
    ));
    $cold = (new UirEmitter)->emit(app(DocumentBuilder::class)->build('default', app(TypeEngine::class))->document);

    // The `not` is what makes the equality worth having: the degradation really is visible in the
    // bytes, so a poisoned store would have shown up here.
    expect($warm)->toBe($cold)
        ->and($degraded)->not->toBe($cold);
});
