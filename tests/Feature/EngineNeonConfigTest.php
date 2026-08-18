<?php

declare(strict_types=1);

use Docuccino\Laravel\Engine\EnginePackage;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * `engine.neon` points the analyser at the application's own PHPStan config. Configuring one that
 * isn't there is a mistake the build can survive — inference just runs without it — so it degrades and
 * says so, rather than failing or going quiet.
 */
it('warns, portably, when engine.neon names a file that is not there', function (): void {
    config()->set('docuccino.engine.neon', 'phpstan.neon');

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    $warnings = diagnosticsCoded($result->diagnostics, 'config.engine-neon-missing');
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->severity->value)->toBe('warning')
        ->and($warnings[0]->message)->toContain('phpstan.neon')
        // The reader is told where to go and what to change, and no machine path reaches the document.
        ->and($warnings[0]->message)->not->toContain(base_path())
        ->and($warnings[0]->help)->toContain('config/docuccino.php')
        // Degraded, not failed: the document is the one an unconfigured build would have produced.
        ->and($result->document->toArray()['paths'] ?? [])->not->toBe([]);
});

it('says nothing about a file that is there', function (): void {
    $neon = sys_get_temp_dir().'/docuccino-user-'.uniqid('', true).'.neon';
    file_put_contents($neon, "parameters:\n");
    config()->set('docuccino.engine.neon', $neon);

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    expect(diagnosticsCoded($result->diagnostics, 'config.engine-neon-missing'))->toBe([]);

    @unlink($neon);
});

it('says nothing when nothing is configured', function (): void {
    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    expect(diagnosticsCoded($result->diagnostics, 'config.engine-neon-missing'))->toBe([]);
});

it('stays quiet where the file could not have been read anyway', function (string $mode, bool $installed): void {
    // Nothing was going to analyse: `null` opted out, and an absent package has its own warning. A
    // config key nobody was ever going to reach is not news.
    config()->set('docuccino.engine.mode', $mode);
    config()->set('docuccino.engine.neon', 'phpstan.neon');
    if (! $installed) {
        app()->instance(EnginePackage::class, new EnginePackage(static fn (string $class): bool => false));
    }

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    expect(diagnosticsCoded($result->diagnostics, 'config.engine-neon-missing'))->toBe([]);
})->with([
    'inference opted out' => ['null', true],
    'engine not installed' => ['in-process', false],
]);
