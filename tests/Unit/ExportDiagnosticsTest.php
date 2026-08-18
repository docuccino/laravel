<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
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
    'yaml unsupported' => [['targets' => [['format' => 'uir', 'path' => 'x.yaml']]], 'config.export-yaml-unsupported', Severity::Error],
    'duplicate path' => [['targets' => [
        ['format' => 'openapi-3.2', 'path' => 'x.json'],
        ['format' => 'openapi-3.1', 'path' => 'x.json'],
    ]], 'config.export-duplicate-path', Severity::Error],
    'duplicate format' => [['targets' => [
        ['format' => 'uir', 'path' => 'a.json'],
        ['format' => 'uir', 'path' => 'b.json'],
    ]], 'config.export-duplicate-format', Severity::Error],
    'path ignored' => [[
        'path' => 'docs/openapi.json',
        'targets' => [['format' => 'uir', 'path' => 'a.json']],
    ], 'config.export-path-ignored', Severity::Info],
]);

it('lists the valid formats when one is unknown, so the fix is in the message', function (): void {
    $diagnostics = exportDiagnosticsFor(['targets' => [['format' => 'swagger-2.0', 'path' => 'x.json']]]);

    expect($diagnostics[0]->message)->toContain('swagger-2.0')
        ->and($diagnostics[0]->message)->toContain('openapi-3.2')
        ->and($diagnostics[0]->message)->toContain('uir');
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
        ['format' => 'uir', 'path' => 'docs/api.uir.json'],
    ]]],
    'nothing configured' => [null],
]);

it('reads only an error as fatal', function (): void {
    $error = exportDiagnosticsFor(['targets' => [['format' => 'nope', 'path' => 'x.json']]]);
    $info = exportDiagnosticsFor(['path' => 'a.json', 'targets' => [['format' => 'uir', 'path' => 'b.json']]]);

    // An info diagnostic reports dead config; it must not stop a run that is otherwise fine.
    expect(ExportDiagnostics::fatal($error))->toBeTrue()
        ->and(ExportDiagnostics::fatal($info))->toBeFalse()
        ->and(ExportDiagnostics::fatal([]))->toBeFalse();
});
