<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Engine\EnginePackage;
use Docuccino\Laravel\Engine\LazyTypeEngine;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * A build whose fragments are all warm asks the engine nothing, so it must not pay to boot one —
 * every command resolves a TypeEngine before it runs, and booting PHPStan to answer zero questions
 * is most of a warm build. What the deferral must not change: the bytes, or the build's one report
 * on the state of inference.
 */
afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/docuccino-lazybuild-*') ?: [] as $dir) {
        array_map('unlink', glob($dir.'/*') ?: []);
        @unlink($dir.'/.gitignore');
        @rmdir($dir);
    }
});

it('builds the engine once on a cold build, never on a fully warm one, and emits the same bytes', function (): void {
    config()->set('docuccino.cache.enabled', true);
    config()->set('docuccino.cache.path', sys_get_temp_dir().'/docuccino-lazybuild-'.uniqid('', true));

    $coldBuilds = 0;
    app()->instance(TypeEngine::class, new LazyTypeEngine(function () use (&$coldBuilds): TypeEngine {
        $coldBuilds++;

        return WorkbenchEngine::make();
    }, StubTypeEngine::class));
    $cold = (new UirEmitter)->emit(generateDocument()->document);

    // A fresh wrapper stands in for the next process: nothing is memoised from the cold run.
    $warmBuilds = 0;
    app()->instance(TypeEngine::class, new LazyTypeEngine(function () use (&$warmBuilds): TypeEngine {
        $warmBuilds++;

        return WorkbenchEngine::make();
    }, StubTypeEngine::class));
    $warm = (new UirEmitter)->emit(generateDocument()->document);

    expect($coldBuilds)->toBe(1)
        ->and($warmBuilds)->toBe(0)
        ->and($warm)->toBe($cold);
});

it('still reports the state of inference on a warm build that never wakes the engine', function (string $mode, bool $installed, string $code): void {
    config()->set('docuccino.engine.mode', $mode);
    config()->set('docuccino.cache.enabled', true);
    config()->set('docuccino.cache.path', sys_get_temp_dir().'/docuccino-lazybuild-'.uniqid('', true));
    app()->instance(EnginePackage::class, new EnginePackage(static fn (string $class): bool => $installed));

    app()->instance(TypeEngine::class, new LazyTypeEngine(
        static fn (): TypeEngine => $installed ? WorkbenchEngine::make() : new NullTypeEngine,
        $installed ? StubTypeEngine::class : NullTypeEngine::class,
    ));
    $cold = app(DocumentBuilder::class)->build('default', app(TypeEngine::class));

    // The warm run refuses to boot at all: the diagnostic has to come from the build's own reading of
    // the environment, never from something the engine did.
    app()->instance(TypeEngine::class, new LazyTypeEngine(
        static fn (): TypeEngine => throw new RuntimeException('a fully warm build booted the engine'),
        $installed ? StubTypeEngine::class : NullTypeEngine::class,
    ));
    $warm = app(DocumentBuilder::class)->build('default', app(TypeEngine::class));

    expect(diagnosticsCoded($cold->diagnostics, $code))->toHaveCount(1)
        ->and(diagnosticsCoded($warm->diagnostics, $code))->toHaveCount(1)
        // Proof the warm run really was warm: a booted engine would have thrown, and every route
        // would have come back a skeleton.
        ->and((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document));
})->with([
    'engine absent' => ['in-process', false, 'engine.not-installed'],
    'mode not wired' => ['orchestrated', true, 'engine.mode-not-wired'],
]);
