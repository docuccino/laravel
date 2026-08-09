<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * The docuccino:export CLI surface: format selection (unknown formats error rather than silently
 * falling back), --yaml, --provenance detail, and unknown-document handling.
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
});

function exportTo(array $options): string
{
    $out = sys_get_temp_dir().'/docuccino-surface-'.uniqid().'.out';
    test()->artisan('docuccino:export', ['--out' => $out] + $options)->assertSuccessful();
    $contents = (string) file_get_contents($out);
    @unlink($out);

    return $contents;
}

it('errors on an unknown --format instead of falling back', function (): void {
    $this->artisan('docuccino:export', ['--format' => 'swagger-2.0', '--out' => sys_get_temp_dir().'/x.json'])
        ->expectsOutputToContain('Unknown --format')
        ->assertFailed();
});

it('defaults to OpenAPI 3.2 JSON when no format is given', function (): void {
    expect(exportTo([]))->toContain('"openapi": "3.2.0"');
});

it('emits YAML with --yaml', function (): void {
    $yaml = exportTo(['--yaml' => true]);

    // YAML, not JSON: a top-level `openapi:` key and no leading brace.
    expect($yaml)->toContain('openapi:')
        ->and(str_starts_with(trim($yaml), '{'))->toBeFalse();
});

it('omits provenance from UIR with --provenance=none, and includes it by default', function (): void {
    expect(exportTo(['--format' => 'uir', '--provenance' => 'none']))->not->toContain('"provenance"');
    expect(exportTo(['--format' => 'uir']))->toContain('"provenance"');
});

it('fails for an unknown document', function (): void {
    $this->artisan('docuccino:export', ['document' => 'ghost'])
        ->expectsOutputToContain('Unknown document')
        ->assertFailed();
});
