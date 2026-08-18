<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * Multi-target export end to end: one build writes every configured target, the CLI overrides replace
 * that list rather than filtering it, and a target list the command cannot honour stops the run
 * before anything is built or written.
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
});

/** A scratch directory this test owns, removed on the way out. */
function targetsDir(): string
{
    $dir = sys_get_temp_dir().'/docuccino-targets-'.uniqid('', true);
    @mkdir($dir, 0777, true);

    return $dir;
}

function configureTargets(array $targets): void
{
    config()->set('docuccino.documents.default.export', ['targets' => $targets]);
}

it('writes every configured target from a single build', function (): void {
    $dir = targetsDir();
    configureTargets([
        ['format' => 'openapi-3.2', 'path' => $dir.'/openapi.json'],
        ['format' => 'openapi-3.1', 'path' => $dir.'/openapi-3.1.yaml'],
        ['format' => 'uir', 'path' => $dir.'/api.uir.json'],
    ]);

    $this->artisan('docuccino:export')
        ->expectsOutputToContain('openapi.json (openapi-3.2)')
        ->expectsOutputToContain('openapi-3.1.yaml (openapi-3.1)')
        ->expectsOutputToContain('api.uir.json (uir)')
        ->assertSuccessful();

    expect(file_get_contents($dir.'/openapi.json'))->toContain('"openapi": "3.2.0"')
        // The extension picked the serialisation: this one is YAML, with no flag anywhere.
        ->and(file_get_contents($dir.'/openapi-3.1.yaml'))->toContain('openapi: 3.1.1')
        ->and(str_starts_with(trim((string) file_get_contents($dir.'/openapi-3.1.yaml')), '{'))->toBeFalse()
        ->and(file_get_contents($dir.'/api.uir.json'))->toContain('"uir":');
});

it('writes a Postman collection alongside the OpenAPI document', function (): void {
    $dir = targetsDir();
    configureTargets([
        ['format' => 'openapi-3.2', 'path' => $dir.'/openapi.json'],
        ['format' => 'postman', 'path' => $dir.'/collection.json'],
    ]);

    $this->artisan('docuccino:export')
        ->expectsOutputToContain('collection.json (postman)')
        ->assertSuccessful();

    $collection = json_decode((string) file_get_contents($dir.'/collection.json'), true);

    expect($collection['info']['schema'])->toBe('https://schema.getpostman.com/json/collection/v2.1.0/collection.json')
        ->and($collection['item'])->not->toBeEmpty();
});

it('rejects a YAML path for a Postman target', function (): void {
    // Postman imports JSON only, so a `.yaml` collection is a file it refuses.
    configureTargets([['format' => 'postman', 'path' => targetsDir().'/collection.yaml']]);

    $this->artisan('docuccino:export')
        ->expectsOutputToContain('config.export-yaml-unsupported')
        ->assertFailed();
});

it('writes byte-identical artifacts across two runs', function (): void {
    // Determinism has to hold for every format the build feeds, not only the one under test elsewhere.
    $dir = targetsDir();
    $paths = [$dir.'/openapi.json', $dir.'/openapi-3.1.yaml', $dir.'/collection.json'];

    configureTargets([
        ['format' => 'openapi-3.2', 'path' => $paths[0]],
        ['format' => 'openapi-3.1', 'path' => $paths[1]],
        ['format' => 'postman', 'path' => $paths[2]],
    ]);

    $this->artisan('docuccino:export')->assertSuccessful();
    $first = array_map(file_get_contents(...), $paths);

    $this->artisan('docuccino:export')->assertSuccessful();

    expect(array_map(file_get_contents(...), $paths))->toBe($first);
});

it('creates missing directories for a target', function (): void {
    $dir = targetsDir();
    configureTargets([['format' => 'openapi-3.2', 'path' => $dir.'/nested/deeper/openapi.json']]);

    $this->artisan('docuccino:export')->assertSuccessful();

    expect(file_exists($dir.'/nested/deeper/openapi.json'))->toBeTrue();
});

it('replaces the configured list when --format names one format', function (): void {
    $dir = targetsDir();
    configureTargets([
        ['format' => 'openapi-3.2', 'path' => $dir.'/openapi.json'],
        ['format' => 'uir', 'path' => $dir.'/api.uir.json'],
    ]);

    $this->artisan('docuccino:export', ['--format' => 'uir'])->assertSuccessful();

    // Only the named format was written — and it landed in the path that target configured.
    expect(file_exists($dir.'/api.uir.json'))->toBeTrue()
        ->and(file_exists($dir.'/openapi.json'))->toBeFalse();
});

it('still writes a format that no target configures', function (): void {
    $dir = targetsDir();
    configureTargets([['format' => 'uir', 'path' => $dir.'/api.uir.json']]);

    // --format asks for a file NOW; it does not mean "only if you already configured one".
    $this->artisan('docuccino:export', ['--format' => 'openapi-3.0', '--out' => $dir.'/legacy.json'])
        ->assertSuccessful();

    expect(file_get_contents($dir.'/legacy.json'))->toContain('"openapi": "3.0.4"')
        ->and(file_exists($dir.'/api.uir.json'))->toBeFalse();
});

it('finds the override target by format, not by position', function (): void {
    $dir = targetsDir();

    foreach ([['a', 'openapi-3.1', 'openapi-3.2'], ['b', 'openapi-3.2', 'openapi-3.1']] as [$run, $first, $second]) {
        config()->set('docuccino.documents.default.export', ['targets' => [
            ['format' => $first, 'path' => $dir.'/'.$run.'-'.$first.'.json'],
            ['format' => $second, 'path' => $dir.'/'.$run.'-'.$second.'.json'],
        ]]);

        $this->artisan('docuccino:export', ['--format' => 'openapi-3.1'])->assertSuccessful();

        // Whichever order the list is written in, 3.1 lands in the 3.1 target.
        expect(file_exists($dir.'/'.$run.'-openapi-3.1.json'))->toBeTrue()
            ->and(file_exists($dir.'/'.$run.'-openapi-3.2.json'))->toBeFalse();
    }
});

it('refuses to build when a target list cannot be honoured', function (array $targets, string $code): void {
    $dir = targetsDir();
    configureTargets($targets);

    $this->artisan('docuccino:export')
        ->expectsOutputToContain($code)
        ->assertFailed();

    // Nothing was written: the check runs before the (expensive) build, not after it.
    expect(glob($dir.'/*'))->toBe([]);
})->with([
    'unknown format' => [[['format' => 'swagger-2.0', 'path' => '/tmp/x.json']], 'config.export-unknown-format'],
    'empty list' => [[], 'config.export-no-targets'],
    'malformed entry' => [['nope'], 'config.export-target-shape'],
    'yaml on a json-only format' => [[['format' => 'uir', 'path' => '/tmp/x.yaml']], 'config.export-yaml-unsupported'],
    'two targets one path' => [[
        ['format' => 'openapi-3.2', 'path' => '/tmp/same.json'],
        ['format' => 'openapi-3.1', 'path' => '/tmp/same.json'],
    ], 'config.export-duplicate-path'],
    'two targets one format' => [[
        ['format' => 'uir', 'path' => '/tmp/a.json'],
        ['format' => 'uir', 'path' => '/tmp/b.json'],
    ], 'config.export-duplicate-format'],
]);

it('refuses when two documents would write the same file', function (): void {
    $dir = targetsDir();
    $shared = $dir.'/shared.json';

    config()->set('docuccino.documents.default.export', ['targets' => [['format' => 'openapi-3.2', 'path' => $shared]]]);
    config()->set('docuccino.documents.admin', [
        'info' => ['title' => 'Admin', 'version' => '1.0.0'],
        'routes' => ['include' => ['api/admin/*']],
        'export' => ['targets' => [['format' => 'openapi-3.2', 'path' => $shared]]],
    ]);

    $this->artisan('docuccino:export')
        ->expectsOutputToContain('config.export-path-collision')
        ->assertFailed();

    expect(file_exists($shared))->toBeFalse();
});

it('says a leftover export.path writes nothing, and still exports', function (): void {
    $dir = targetsDir();
    config()->set('docuccino.documents.default.export', [
        'path' => $dir.'/ignored.json',
        'targets' => [['format' => 'uir', 'path' => $dir.'/api.uir.json']],
    ]);

    $this->artisan('docuccino:export')
        ->expectsOutputToContain('config.export-path-ignored')
        ->assertSuccessful();

    expect(file_exists($dir.'/api.uir.json'))->toBeTrue()
        ->and(file_exists($dir.'/ignored.json'))->toBeFalse();
});

it('fails without claiming success when a target cannot be written', function (): void {
    $dir = targetsDir();
    $blocked = $dir.'/blocked';
    file_put_contents($blocked, 'not a directory');

    // The target's parent is a FILE, so neither mkdir nor the write can succeed.
    configureTargets([['format' => 'openapi-3.2', 'path' => $blocked.'/openapi.json']]);

    $this->artisan('docuccino:export')
        ->doesntExpectOutputToContain('Wrote')
        ->assertFailed();
});

it('rejects --yaml for a format with no YAML serialisation', function (): void {
    $this->artisan('docuccino:export', ['--format' => 'uir', '--yaml' => true, '--out' => targetsDir().'/x.json'])
        ->expectsOutputToContain('--yaml cannot be used with --format=uir')
        ->assertFailed();
});
