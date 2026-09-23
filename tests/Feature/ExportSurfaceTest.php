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

it('tells a pipeline still passing --format=uir what to pass instead', function (): void {
    // The retired id ships with no alias, so this refusal IS the upgrade instruction, and it is the
    // first thing an existing CI pipeline meets. Run rather than claimed.
    $this->artisan('docuccino:export', ['--format' => 'uir', '--out' => sys_get_temp_dir().'/x.json'])
        ->expectsOutputToContain('"uir" is now "full".')
        ->assertFailed();
});

it('defaults to OpenAPI 3.2 JSON when no format is given', function (): void {
    expect(exportTo([]))->toContain('"openapi": "3.2.0"');
});

it('emits each downlevel format on request', function (string $format, string $marker): void {
    expect(exportTo(['--format' => $format]))->toContain($marker);
})->with([
    'openapi-3.1' => ['openapi-3.1', '"openapi": "3.1.1"'],
    'openapi-3.0' => ['openapi-3.0', '"openapi": "3.0.4"'],
]);

it('reports what a downlevel could not carry as it writes', function (): void {
    $this->artisan('docuccino:export', ['--format' => 'openapi-3.0', '--out' => sys_get_temp_dir().'/docuccino-notes-'.uniqid().'.json'])
        ->expectsOutputToContain('downlevel.const')
        ->assertSuccessful();
});

it('emits YAML with --yaml', function (): void {
    $yaml = exportTo(['--yaml' => true]);

    // YAML, not JSON: a top-level `openapi:` key and no leading brace.
    expect($yaml)->toContain('openapi:')
        ->and(str_starts_with(trim($yaml), '{'))->toBeFalse();
});

it('omits provenance from the full artifact with --provenance=none, and includes it by default', function (): void {
    expect(exportTo(['--format' => 'full', '--provenance' => 'none']))->not->toContain('"provenance"');
    expect(exportTo(['--format' => 'full']))->toContain('"provenance"');
});

it('errors on an unknown --provenance instead of coercing it to winners', function (): void {
    // Coercing would ship an artifact carrying provenance the caller asked to leave out.
    $this->artisan('docuccino:export', ['--provenance' => 'nome', '--out' => sys_get_temp_dir().'/x.json'])
        ->expectsOutputToContain('Unknown --provenance "nome"; expected one of: none, winners, full.')
        ->assertFailed();
});

/*
 * What this command prints, it did not write: an option as it was typed, a format id and a path out of
 * `docuccino.yaml`. Symfony's formatter obeys `<…>` in everything a command writes and nothing strips
 * ANSI, so a Makefile or a CI matrix interpolating a variable into `--format` is enough to recolour the
 * log — and a `\r` ahead of it forges a "Wrote …" line over the failure that really happened. Both
 * directions are proved below: the tags survive as text, and the control sequence does not survive as
 * one.
 */
it('prints an option it could not use as text rather than as terminal instructions', function (string $option, string $expected): void {
    $this->artisan('docuccino:export', [$option => "<fg=red>nope</>\x1B[2K", '--out' => sys_get_temp_dir().'/x.json'])
        ->expectsOutputToContain($expected.' "<fg=red>nope</>\x1B[2K"')
        ->assertFailed();
})->with([
    '--format' => ['--format', 'Unknown --format'],
    '--provenance' => ['--provenance', 'Unknown --provenance'],
]);

it('prints the path it wrote as text too, that being the line a forged one imitates', function (): void {
    // The success line, not an error: this is the one a forged `✔ Wrote docs/openapi.json` would have to
    // look like, and the path comes from `--out` or from a configured target either way.
    $out = sys_get_temp_dir().'/docuccino-<fg=green>ok</>-'.uniqid().'.json';

    $this->artisan('docuccino:export', ['--out' => $out])
        ->expectsOutputToContain('Wrote '.$out.' (openapi-3.2).')
        ->assertSuccessful();

    @unlink($out);
});

it('accepts every provenance level', function (string $level): void {
    expect(exportTo(['--format' => 'full', '--provenance' => $level]))->toContain('"x-docuccino"');
})->with(['none', 'winners', 'full']);

it('keeps node identities by default, and drops them with --drop-ids', function (): void {
    // The nested `x-docuccino` never survives OAS emission — it holds provenance (file, line, symbol),
    // which has no business in a published spec. The flat id is the half worth keeping: an opaque hash of
    // members the document already publishes, and the only thing that lets `docuccino:diff` pair this
    // artifact by identity instead of falling back to method + path.
    expect(exportTo([]))->toContain('"x-docuccino-id"')
        ->and(exportTo([]))->not->toContain('"x-docuccino"')
        ->and(exportTo([]))->not->toContain('provenance');

    expect(exportTo(['--drop-ids' => true]))->not->toContain('x-docuccino');
});

it('fails for an unknown document', function (): void {
    $this->artisan('docuccino:export', ['document' => 'ghost'])
        ->expectsOutputToContain('Unknown document')
        ->assertFailed();
});
