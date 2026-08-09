<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Config\ConfigPaths;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Registry\ConfigDiagnostics;
use Docuccino\Laravel\Support\Paths;

/*
 * Base-path relativisation of every path-like config key.
 *
 * `DocumentConfig::hash()` digests the raw config bag and that digest is emitted as
 * `document.configHash`, so an absolute path in config would fold the generating machine's filesystem
 * layout into a committed artifact. Every in-app path is stored base-path-relative, so the hash follows
 * what a path means rather than how it was spelled. A path genuinely outside the app is kept verbatim and
 * reported as machine-dependent.
 */

/**
 * One document raw-config bag carrying $value at the dotted $key (the shape the real config has).
 *
 * @return array<string, mixed>
 */
function rawWithPath(string $key, string $value): array
{
    $segments = explode('.', $key);
    /** @var string|int $leaf */
    $leaf = array_pop($segments);
    $node = [(is_numeric($leaf) ? (int) $leaf : $leaf) => $value];

    foreach (array_reverse($segments) as $segment) {
        $node = [$segment => $node];
    }

    /** @var array<string, mixed> $node */
    return $node;
}

/** Read the dotted $key back out of a (possibly rewritten) config bag. */
function readPath(array $config, string $key): mixed
{
    $cursor = $config;

    foreach (explode('.', $key) as $segment) {
        if (! is_array($cursor)) {
            return null;
        }
        $cursor = $cursor[is_numeric($segment) ? (int) $segment : $segment] ?? null;
    }

    return $cursor;
}

/**
 * Build the `default` document from a bare raw bag against an arbitrary $basePath — the seam the
 * relativisation lives behind, and the only place these tests need.
 */
function documentFrom(array $raw, string $basePath = '/checkout/one'): DocumentConfig
{
    return (new DocumentConfigFactory($basePath, app()))->make('default', $raw, 'skeleton');
}

/** Every path-like config key, with a representative in-app relative value. */
dataset('pathKeys', [
    'content.dir' => ['content.dir', 'resources/docs/api'],
    'export.path' => ['export.path', 'docs/openapi.json'],
    'info.description.file' => ['info.description.file', 'resources/docs/description.md'],
    'overlays[0]' => ['overlays.0', 'resources/docs/overlays/*.yaml'],
]);

it('leaves a relative path untouched', function (string $key, string $relative): void {
    $document = documentFrom(rawWithPath($key, $relative));

    expect(readPath($document->raw, $key))->toBe($relative);
})->with('pathKeys');

it('relativises an absolute path inside the base path, hashing identically to the relative form', function (string $key, string $relative): void {
    $base = '/checkout/one';

    $absolute = documentFrom(rawWithPath($key, $base.'/'.$relative), $base);
    $written = documentFrom(rawWithPath($key, $relative), $base);

    // Same meaning, same stored value, same emitted hash — however the user spelled it.
    expect(readPath($absolute->raw, $key))->toBe($relative)
        ->and($absolute->hash())->toBe($written->hash());
})->with('pathKeys');

it('keeps a path outside the base path and reports it as machine-dependent', function (string $key, string $relative): void {
    $outside = '/opt/shared/'.$relative;

    $document = documentFrom(rawWithPath($key, $outside));

    expect(readPath($document->raw, $key))->toBe($outside);

    $diagnostics = array_values(array_filter(
        ConfigDiagnostics::for($document),
        static fn (Diagnostic $d): bool => $d->code === 'config.machine-dependent-path',
    ));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Info)
        ->and($diagnostics[0]->message)->toContain($key)
        ->and($diagnostics[0]->message)->toContain($outside);
})->with('pathKeys');

it('emits no machine-dependent-path diagnostic for an in-app path', function (string $key, string $relative): void {
    $base = '/checkout/one';

    foreach ([$relative, $base.'/'.$relative] as $configured) {
        expect(ConfigDiagnostics::for(documentFrom(rawWithPath($key, $configured), $base)))->toBe([]);
    }
})->with('pathKeys');

it('hashes identically across two checkouts of the same app at different paths', function (string $key, string $relative): void {
    // CI, a colleague's laptop and a container each mount the app somewhere else, and the same config
    // spelled absolutely against each checkout's own base path still emits the same bytes.
    $one = documentFrom(rawWithPath($key, '/checkout/one/'.$relative), '/checkout/one');
    $two = documentFrom(rawWithPath($key, '/home/dev/projects/app/'.$relative), '/home/dev/projects/app');

    expect($one->hash())->toBe($two->hash());
})->with('pathKeys');

it('relativises every path-like key at once, leaving the rest of the bag alone', function (): void {
    $base = '/checkout/one';

    $document = documentFrom([
        'info' => ['title' => 'API', 'description' => ['file' => $base.'/resources/docs/description.md']],
        'content' => ['dir' => $base.'/resources/docs/api'],
        'overlays' => [$base.'/resources/docs/overlays/*.yaml', 'resources/extra/*.yaml'],
        'export' => ['path' => $base.'/docs/openapi.json', 'formats' => ['openapi-3.2']],
        // A URL path, not a filesystem path — the viewer route survives verbatim.
        'viewer' => ['route' => '/docs/api', 'source' => 'generate'],
    ], $base);

    expect($document->contentDir())->toBe('resources/docs/api')
        ->and($document->exportPath())->toBe('docs/openapi.json')
        ->and($document->overlays)->toBe(['resources/docs/overlays/*.yaml', 'resources/extra/*.yaml'])
        ->and(readPath($document->raw, 'info.description.file'))->toBe('resources/docs/description.md')
        ->and(readPath($document->raw, 'viewer.route'))->toBe('/docs/api');
});

it('leaves a non-string value at a path-like key alone', function (): void {
    // `content.dir => null` is the shipped default, and a mis-typed value isn't coerced.
    $document = documentFrom(['content' => ['dir' => null], 'overlays' => 'not-a-list', 'export' => ['path' => 42]]);

    expect($document->contentDir())->toBeNull()
        ->and(readPath($document->raw, 'overlays'))->toBe('not-a-list')
        ->and(readPath($document->raw, 'export.path'))->toBe(42)
        ->and(ConfigPaths::machineDependent($document->raw))->toBe([]);
});

it('leaves a non-string entry inside an overlay list alone', function (): void {
    $document = documentFrom(['overlays' => ['/checkout/one/a.yaml', 7, null]], '/checkout/one');

    expect(readPath($document->raw, 'overlays'))->toBe(['a.yaml', 7, null]);
});

it('resolves a relativised path back to the very same file it was configured with', function (): void {
    // Relativisation doesn't change what gets read — the base-path join is its inverse.
    $base = sys_get_temp_dir().'/docuccino-relativise-'.uniqid();
    $document = documentFrom(['export' => ['path' => $base.'/docs/openapi.json']], $base);

    expect(Paths::absolute($document->exportPath(), $base))->toBe($base.'/docs/openapi.json');
});

it('expresses a path relative to a base path, or reports it as outside', function (?string $expected, string $path, string $base): void {
    expect(Paths::relative($path, $base))->toBe($expected);
})->with([
    'already relative' => ['docs/openapi.json', 'docs/openapi.json', '/app'],
    'inside the base path' => ['docs/openapi.json', '/app/docs/openapi.json', '/app'],
    'inside, dot segments collapsed' => ['docs/openapi.json', '/app/nested/../docs/./openapi.json', '/app'],
    'inside, trailing slash on the base' => ['docs', '/app/docs', '/app/'],
    'the base path itself' => ['.', '/app', '/app'],
    'a sibling with a shared prefix' => [null, '/app-other/docs', '/app'],
    'outside the base path' => [null, '/opt/shared/docs', '/app'],
    'escaping via dot segments' => [null, '/app/../opt/docs', '/app'],
    'the filesystem root as base' => ['opt/docs', '/opt/docs', '/'],
]);
