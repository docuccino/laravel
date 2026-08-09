<?php

declare(strict_types=1);

use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * The orchestrated and caching engine modes exist in the inference engine but are not yet plumbed
 * through the adapter, so selecting one silently runs in-process. The build surfaces that as a
 * warning diagnostic rather than letting it pass unnoticed. (An absent engine package suppresses this
 * warning in favour of its own — see EngineLessTest.)
 */
it('warns when a not-yet-wired engine mode is selected', function (string $mode): void {
    config()->set('docuccino.engine.mode', $mode);

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    $warnings = diagnosticsCoded($result->diagnostics, 'engine.mode-not-wired');
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->severity->value)->toBe('warning');
})->with(['orchestrated', 'caching']);

it('stays silent on the default in-process engine mode', function (): void {
    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    expect(diagnosticsCoded($result->diagnostics, 'engine.mode-not-wired'))->toBe([]);
});
