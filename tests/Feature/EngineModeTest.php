<?php

declare(strict_types=1);

use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * `in-process` and `null` are the modes. Anything else — a typo, or a mode an earlier version had and
 * this one dropped — runs in-process rather than failing the build, and says so once. (An absent
 * engine package suppresses this warning in favour of its own — see EngineLessTest.)
 */
it('warns and runs in-process when the mode is not one it knows', function (string $mode): void {
    config()->set('docuccino.engine.mode', $mode);

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    $warnings = diagnosticsCoded($result->diagnostics, 'engine.mode-unknown');
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->severity->value)->toBe('warning')
        ->and($warnings[0]->message)->toContain($mode)
        ->and($warnings[0]->help)->toContain('"in-process"')
        // Degraded, not failed: the document is the one in-process would have built.
        ->and($result->document->toArray()['paths'] ?? [])->not->toBe([]);
})->with([
    // The two modes this package used to offer, so an app still setting one keeps generating.
    'orchestrated' => 'orchestrated',
    'caching' => 'caching',
    'typo' => 'in_process',
]);

it('stays silent on the modes it knows', function (string $mode): void {
    config()->set('docuccino.engine.mode', $mode);

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    expect(diagnosticsCoded($result->diagnostics, 'engine.mode-unknown'))->toBe([]);
})->with(['in-process', 'null']);
