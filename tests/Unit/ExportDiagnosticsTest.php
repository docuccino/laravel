<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Config\ExportDiagnostics;

/**
 * The problem → code map. Every structured problem a document can report about its export targets
 * gets a code and a message naming where to look, and an unrecognised one still degrades to an error
 * rather than passing as a clean config.
 */
function exportDiagnosticsFor(mixed $export): array
{
    return ExportDiagnostics::for(new DocumentConfig(key: 'default', info: [], raw: ['export' => $export]));
}

it('names every problem a target list can have', function (mixed $export, string $code, Severity $severity): void {
    $diagnostics = exportDiagnosticsFor($export);

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toContain($code);

    $matching = array_values(array_filter($diagnostics, static fn (Diagnostic $d): bool => $d->code === $code));
    expect($matching[0]->severity)->toBe($severity)
        // Every message points at the key the reader has to edit.
        ->and($matching[0]->message)->toContain('documents.default.export');
})->with([
    'empty list' => [['targets' => []], 'config.export-no-targets', Severity::Error],
    'malformed entry' => [['targets' => ['nope']], 'config.export-target-shape', Severity::Error],
    'unknown format' => [['targets' => [['format' => 'nope', 'path' => 'x.json']]], 'config.export-unknown-format', Severity::Error],
    'yaml unsupported' => [['targets' => [['format' => 'full', 'path' => 'x.yaml']]], 'config.export-yaml-unsupported', Severity::Error],
    'duplicate path' => [['targets' => [
        ['format' => 'openapi-3.2', 'path' => 'x.json'],
        ['format' => 'openapi-3.1', 'path' => 'x.json'],
    ]], 'config.export-duplicate-path', Severity::Error],
    'duplicate format' => [['targets' => [
        ['format' => 'full', 'path' => 'a.json'],
        ['format' => 'full', 'path' => 'b.json'],
    ]], 'config.export-duplicate-format', Severity::Error],
    'path ignored' => [[
        'path' => 'docs/openapi.json',
        'targets' => [['format' => 'full', 'path' => 'a.json']],
    ], 'config.export-path-ignored', Severity::Info],
]);

it('lists the valid formats when one is unknown, so the fix is in the message', function (): void {
    $diagnostics = exportDiagnosticsFor(['targets' => [['format' => 'swagger-2.0', 'path' => 'x.json']]]);

    // The whole list, built the way the message builds it. A bare `toContain('full')` would pass on
    // four common letters, and `', full,'` — the first attempt at fixing that — silently depended on
    // `full` sitting in the middle of `Formats::TABLE`, whose order is load-bearing for other
    // reasons and is meant to be reorderable.
    expect($diagnostics[0]->message)->toContain('swagger-2.0')
        ->and($diagnostics[0]->message)->toContain(implode(', ', Formats::ids()));
});

it('names the replacement when a target still asks for a retired format id', function (): void {
    // A committed docuccino.yaml carrying `format: 'uir'` meets this diagnostic and nothing else, so
    // it owes the same sentence the CLI gives — a reader should never have to find the release notes.
    $diagnostics = exportDiagnosticsFor(['targets' => [['format' => 'uir', 'path' => 'x.json']]]);

    expect($diagnostics[0]->code)->toBe('config.export-unknown-format')
        ->and($diagnostics[0]->message)->toContain('"uir" is now "full".');
});

it('points at the offending entry by index', function (): void {
    $diagnostics = exportDiagnosticsFor(['targets' => [
        ['format' => 'openapi-3.2', 'path' => 'a.json'],
        ['format' => 'nope', 'path' => 'b.json'],
    ]]);

    expect($diagnostics[0]->message)->toContain('documents.default.export.targets.1');
});

it('says nothing about a config with nothing wrong with it', function (mixed $export): void {
    expect(exportDiagnosticsFor($export))->toBe([]);
})->with([
    'shorthand' => [['path' => 'docs/openapi.json']],
    'one target' => [['targets' => [['format' => 'openapi-3.2', 'path' => 'docs/openapi.json']]]],
    'several targets' => [['targets' => [
        ['format' => 'openapi-3.2', 'path' => 'docs/openapi.json'],
        ['format' => 'full', 'path' => 'docs/api.uir.json'],
    ]]],
    'nothing configured' => [null],
]);

it('reads only an error as fatal', function (): void {
    $error = exportDiagnosticsFor(['targets' => [['format' => 'nope', 'path' => 'x.json']]]);
    $info = exportDiagnosticsFor(['path' => 'a.json', 'targets' => [['format' => 'full', 'path' => 'b.json']]]);

    // An info diagnostic reports dead config; it must not stop a run that is otherwise fine.
    expect(ExportDiagnostics::fatal($error))->toBeTrue()
        ->and(ExportDiagnostics::fatal($info))->toBeFalse()
        ->and(ExportDiagnostics::fatal([]))->toBeFalse();
});
