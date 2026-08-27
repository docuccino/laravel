<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Support\JsonValue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * Feature coverage for docuccino:diff — self-diff is empty, a removed operation is breaking, the
 * versioning policy gate (`--enforce`), JSON output, and the `--against=<git-ref>` contract.
 */
function writeArtifact(?callable $mutate = null): string
{
    $document = generateDocument()->document;

    // Through the shared reader, as the command itself reads an old artifact: an associative decode
    // flattens every `{}` to `[]`, which is a real difference in an example and would have every
    // self-diff here reporting one.
    /** @var array<string, mixed> $uir */
    $uir = JsonValue::decode((new UirEmitter)->emit($document));

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

/**
 * Rewrites a component schema's `description` in the OLD artifact: an annotation edit and nothing else,
 * at a pointer the fresh document still declares.
 *
 * @param  array<string, mixed>  $uir
 * @return array<string, mixed>
 */
function withEditedSchemaDescription(array $uir): array
{
    $components = is_array($uir['components'] ?? null) ? $uir['components'] : [];
    $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
    $article = is_array($schemas['Article'] ?? null) ? $schemas['Article'] : [];

    $article['description'] = 'A description only the committed artifact carries.';
    $schemas['Article'] = $article;
    $components['schemas'] = $schemas;
    $uir['components'] = $components;

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

it('reports no changes against the artifact docuccino:export writes by default', function (): void {
    // Every other case here writes UIR, which is the one thing the advertised workflow doesn't: `export`
    // defaults to OpenAPI with ids kept. "No API changes." alone would not prove the flat ids were read —
    // structural pairing says the same thing about a document diffed against itself — so this asserts the
    // pairing MODE and that every kind of node found its counterpart.
    bindStubEngine();

    $path = sys_get_temp_dir().'/docuccino-export-'.uniqid().'.json';

    $this->artisan('docuccino:export', ['document' => 'default', '--out' => $path])->assertSuccessful();

    $exit = Artisan::call('docuccino:diff', ['old' => $path, '--format' => 'json']);

    /** @var array<string, mixed> $result */
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($result['pairing'])->toBe('identity')
        ->and($result['disjointIdentities'])->toBe([])
        ->and($result['counts']['total'])->toBe(0);

    @unlink($path);
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

it('fails on an artifact that is valid JSON but not a document', function (string $json): void {
    // Each of these parses, so the JSON guard lets it through — and hydrating a non-array is a TypeError,
    // i.e. a stack trace of absolute paths in a CI log instead of the command's own error.
    bindStubEngine();

    $old = sys_get_temp_dir().'/docuccino-scalar-'.uniqid().'.json';
    file_put_contents($old, $json);

    $this->artisan('docuccino:diff', ['old' => $old])
        ->expectsOutputToContain('not an object')
        ->assertFailed();

    @unlink($old);
})->with(['null', '5', '"x"', 'true']);

it('fails when git show cannot read the ref', function (): void {
    bindStubEngine();

    Process::fake(['*git*show*' => Process::result(errorOutput: 'fatal: bad revision', exitCode: 128)]);

    $this->artisan('docuccino:diff', ['old' => 'docs/openapi.json', '--against' => 'HEAD'])
        ->expectsOutputToContain('git show')
        ->assertFailed();
});

it('neutralises Symfony markup in a change path without disturbing a legitimate one', function (): void {
    // Both names come off the old artifact, which nobody re-read before diffing. Core already made every
    // value it renders plain, so what is still interpreted here is Symfony's own markup: `<fg=red>` would
    // recolour the rest of the operator's report, and a schema named after a generic must survive intact.
    bindStubEngine();

    $old = writeArtifact(function (array $uir): array {
        $components = is_array($uir['components'] ?? null) ? $uir['components'] : [];
        $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
        $schemas['Gone<fg=red>'] = ['type' => 'object'];
        $schemas['Paged_array<int, string>'] = ['type' => 'object'];
        $components['schemas'] = $schemas;
        $uir['components'] = $components;

        return $uir;
    });

    Artisan::call('docuccino:diff', ['old' => $old]);
    $output = Artisan::output();

    expect($output)->toContain('components.schemas.Gone<fg=red>')
        ->and($output)->toContain('components.schemas.Paged_array<int, string>');

    @unlink($old);
});

it('hands --format=json to the machine reading it byte for byte', function (): void {
    // The terminal report is formatter input; this half is not. Written at OUTPUT_NORMAL the formatter reads
    // `<error>` in an artifact-derived name as markup and drops it — the payload still parses, and a CI gate
    // decides on a name the artifact never carried.
    bindStubEngine();

    $name = 'Gone<error>DANGER</error>';

    $old = writeArtifact(function (array $uir) use ($name): array {
        $components = is_array($uir['components'] ?? null) ? $uir['components'] : [];
        $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
        $schemas[$name] = ['type' => 'object'];
        $components['schemas'] = $schemas;
        $uir['components'] = $components;

        return $uir;
    });

    Artisan::call('docuccino:diff', ['old' => $old, '--format' => 'json']);

    /** @var array{changes: list<array{path: string}>} $decoded */
    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $paths = array_column($decoded['changes'], 'path');

    expect($paths)->toContain('components.schemas.'.$name);

    @unlink($old);
});

it('neutralises Symfony markup in a version string the policy reports back', function (): void {
    bindStubEngine();
    config()->set('docuccino.documents.default.versioning', 'semver');

    $old = writeArtifact(function (array $uir): array {
        $info = is_array($uir['info'] ?? null) ? $uir['info'] : [];
        $info['version'] = '1.0.0<fg=red>';
        $uir['info'] = $info;

        return $uir;
    });

    Artisan::call('docuccino:diff', ['old' => $old, '--enforce' => true]);

    expect(Artisan::output())->toContain('1.0.0<fg=red>');

    @unlink($old);
});

it('makes what git said, and the ref it was asked for, safe to print', function (): void {
    // Core renders nothing on this path: the ref comes from a workflow variable and the rest is a
    // subprocess's stderr, so both halves are still owed here rather than upstream.
    bindStubEngine();

    Process::fake(['*git*show*' => Process::result(errorOutput: "fatal: bad revision\x1B[2K\rup to date", exitCode: 128)]);

    Artisan::call('docuccino:diff', ['old' => 'docs/openapi.json', '--against' => "HEAD\x1B[31m<fg=red>"]);
    $output = Artisan::output();

    expect($output)->not->toContain("\x1B")
        ->and($output)->not->toContain("\r")
        ->and($output)->toContain('HEAD\x1B[31m<fg=red>')
        ->and($output)->toContain('fatal: bad revision\x1B[2K\x0Dup to date');
});

it('rejects a git ref that starts with a dash', function (): void {
    bindStubEngine();

    $this->artisan('docuccino:diff', ['old' => 'docs/openapi.json', '--against' => '--upload-pack=evil'])
        ->expectsOutputToContain('must not start with')
        ->assertFailed();
});

it('passes enforcement for an annotation-only change, and still fails once a real break joins it', function (): void {
    bindStubEngine();
    config()->set('docuccino.documents.default.versioning', 'semver');

    $old = writeArtifact(withEditedSchemaDescription(...));

    expect(Artisan::call('docuccino:diff', ['old' => $old, '--enforce' => true]))->toBe(0);

    // Visible, and under NON-BREAKING: a reviewer asking what moved is still told.
    expect(Artisan::output())->toContain('(0 breaking)')
        ->toContain('NON-BREAKING')
        ->toContain('schema.annotation-changed')
        ->toContain('satisfied');

    @unlink($old);

    // The same annotation edit plus one operation the current document no longer answers.
    $both = writeArtifact(fn (array $uir): array => withExtraOperation(withEditedSchemaDescription($uir)));

    expect(Artisan::call('docuccino:diff', ['old' => $both, '--enforce' => true]))->toBe(1);

    expect(Artisan::output())->toContain('(1 breaking)')
        ->toContain('operation.removed')
        ->toContain('schema.annotation-changed');

    @unlink($both);
});

it('reports a payload it could not encode rather than printing an empty line', function (): void {
    // JSON has no bound on a number literal, so `1e999` parses without complaint and lands as a PHP
    // `INF` — which `json_encode` then refuses. The value reaches `--format=json` because a change
    // carries what moved, and an empty line is the one answer a CI gate cannot read: it parses as
    // neither a changeset nor a failure.
    bindStubEngine();

    $old = writeArtifact(function (array $uir): array {
        $components = is_array($uir['components'] ?? null) ? $uir['components'] : [];
        $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
        $article = is_array($schemas['Article'] ?? null) ? $schemas['Article'] : [];

        $article['enum'] = ['__overflow__'];
        $schemas['Article'] = $article;
        $components['schemas'] = $schemas;
        $uir['components'] = $components;

        return $uir;
    });

    // Written as text: the literal cannot survive `json_encode` on the way in either.
    $text = (string) file_get_contents($old);
    expect($text)->toContain('"__overflow__"');
    file_put_contents($old, str_replace('"__overflow__"', '1e999', $text));

    $this->artisan('docuccino:diff', ['old' => $old, '--format' => 'json'])
        ->expectsOutputToContain('could not be encoded as JSON')
        ->assertFailed();

    @unlink($old);
});
