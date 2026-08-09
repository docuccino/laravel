<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Illuminate\Support\Facades\Process;

/**
 * Feature coverage for docuccino:diff — self-diff is empty, a removed operation is breaking, the
 * versioning policy gate (`--enforce`), JSON output, and the `--against=<git-ref>` contract.
 */
function writeArtifact(?callable $mutate = null): string
{
    $document = generateDocument()->document;

    /** @var array<string, mixed> $uir */
    $uir = json_decode((new UirEmitter)->emit($document), true, flags: JSON_THROW_ON_ERROR);

    if ($mutate !== null) {
        $uir = $mutate($uir);
    }

    $path = sys_get_temp_dir().'/docuccino-old-'.uniqid().'.json';
    file_put_contents($path, (string) json_encode($uir));

    return $path;
}

/**
 * @param  array<string, mixed>  $uir
 * @return array<string, mixed>
 */
function withExtraOperation(array $uir): array
{
    $paths = is_array($uir['paths'] ?? null) ? $uir['paths'] : [];
    $paths['/api/gone'] = ['get' => [
        'x-docuccino' => ['id' => 'op:v1:zzzzzzzzzzzzzzzz'],
        'responses' => ['200' => ['description' => 'OK']],
    ]];
    $uir['paths'] = $paths;

    return $uir;
}

it('reports no changes when diffing a document against itself', function (): void {
    bindStubEngine();

    $old = writeArtifact();

    $this->artisan('docuccino:diff', ['old' => $old])
        ->expectsOutputToContain('No API changes.')
        ->assertSuccessful();

    @unlink($old);
});

it('detects a removed operation as breaking', function (): void {
    bindStubEngine();

    // The old artifact has an extra operation the current document lacks — a breaking removal.
    $old = writeArtifact(withExtraOperation(...));

    $this->artisan('docuccino:diff', ['old' => $old])
        ->expectsOutputToContain('BREAKING')
        ->assertSuccessful(); // without --enforce, diff is informational

    @unlink($old);
});

it('fails a breaking change under semver enforcement without a major bump', function (): void {
    bindStubEngine();
    config()->set('docuccino.documents.default.versioning', 'semver');

    $old = writeArtifact(withExtraOperation(...));

    $this->artisan('docuccino:diff', ['old' => $old, '--enforce' => true])
        ->assertFailed();

    @unlink($old);
});

it('passes enforcement when there are no breaking changes', function (): void {
    bindStubEngine();
    config()->set('docuccino.documents.default.versioning', 'semver');

    $old = writeArtifact();

    $this->artisan('docuccino:diff', ['old' => $old, '--enforce' => true])
        ->assertSuccessful();

    @unlink($old);
});

it('emits machine-readable JSON with --format=json', function (): void {
    bindStubEngine();

    $old = writeArtifact(withExtraOperation(...));

    $this->artisan('docuccino:diff', ['old' => $old, '--format' => 'json'])
        ->expectsOutputToContain('"breaking": true')
        ->assertSuccessful();

    @unlink($old);
});

it('reads the old artifact from a git ref via --against', function (): void {
    bindStubEngine();

    $uir = (new UirEmitter)->emit(generateDocument()->document);
    // The command runs array-form (no shell, security L3), so its Symfony commandline is escaped
    // per-argument ('git' 'show' 'HEAD:docs/openapi.json') — match on the escaped form.
    Process::fake(['*git*show*' => Process::result(output: $uir)]);

    $this->artisan('docuccino:diff', ['old' => 'docs/openapi.json', '--against' => 'HEAD'])
        ->expectsOutputToContain('No API changes.')
        ->assertSuccessful();
});

it('fails when the old artifact is missing', function (): void {
    bindStubEngine();

    $this->artisan('docuccino:diff', ['old' => '/nonexistent/path.json'])
        ->assertFailed();
});

it('fails a breaking change under date-version enforcement without a newer date', function (): void {
    bindStubEngine();
    config()->set('docuccino.documents.default.versioning', 'date');

    $old = writeArtifact(withExtraOperation(...));

    $this->artisan('docuccino:diff', ['old' => $old, '--enforce' => true])
        ->assertFailed();

    @unlink($old);
});

it('passes enforcement for a purely additive (non-breaking) change', function (): void {
    bindStubEngine();
    config()->set('docuccino.documents.default.versioning', 'semver');

    // The old side is missing an operation the current document has → an addition, not a removal.
    $old = writeArtifact(function (array $uir): array {
        $paths = is_array($uir['paths'] ?? null) ? $uir['paths'] : [];
        unset($paths['/api/forms']);
        $uir['paths'] = $paths;

        return $uir;
    });

    $this->artisan('docuccino:diff', ['old' => $old, '--enforce' => true])
        ->assertSuccessful();

    @unlink($old);
});

it('fails when the two documents were built with different identity-algorithm versions', function (): void {
    bindStubEngine();

    // Re-stamp every operation identity to a different algo version → the differ refuses to pair.
    $old = writeArtifact(fn (array $uir): array => json_decode(
        str_replace('op:v1:', 'op:v2:', (string) json_encode($uir)),
        true,
        flags: JSON_THROW_ON_ERROR,
    ));

    $this->artisan('docuccino:diff', ['old' => $old])
        ->expectsOutputToContain('identity-algorithm')
        ->assertFailed();

    @unlink($old);
});

it('fails on a malformed old artifact', function (): void {
    bindStubEngine();

    $old = sys_get_temp_dir().'/docuccino-bad-'.uniqid().'.json';
    file_put_contents($old, '{ this is not json');

    $this->artisan('docuccino:diff', ['old' => $old])
        ->expectsOutputToContain('Could not parse')
        ->assertFailed();

    @unlink($old);
});

it('fails when git show cannot read the ref', function (): void {
    bindStubEngine();

    Process::fake(['*git*show*' => Process::result(errorOutput: 'fatal: bad revision', exitCode: 128)]);

    $this->artisan('docuccino:diff', ['old' => 'docs/openapi.json', '--against' => 'HEAD'])
        ->expectsOutputToContain('git show')
        ->assertFailed();
});

it('rejects a git ref that starts with a dash', function (): void {
    bindStubEngine();

    $this->artisan('docuccino:diff', ['old' => 'docs/openapi.json', '--against' => '--upload-pack=evil'])
        ->expectsOutputToContain('must not start with')
        ->assertFailed();
});
