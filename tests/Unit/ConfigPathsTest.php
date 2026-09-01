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
 *
 * `export.path` is relativised alongside the rest, but is a DESTINATION: it says where an artifact is
 * written, never what it holds, so it sits outside `hash()` and an out-of-tree one reports nothing.
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

/** Path-like keys that SHAPE the document, so they are digested by `hash()`. */
dataset('pathKeys', [
    'api_version.changes[0]' => ['api_version.changes.0', 'app/Api/Versions'],
    'content.dir' => ['content.dir', 'resources/docs/api'],
    'coverage.log' => ['coverage.log', 'storage/docuccino/coverage'],
    'examples.recordings' => ['examples.recordings', 'docs/recordings'],
    'info.description.file' => ['info.description.file', 'resources/docs/description.md'],
    'overlays[0]' => ['overlays.0', 'resources/docs/overlays/*.yaml'],
    'webhooks.dir' => ['webhooks.dir', 'app/Webhooks'],
]);

/** Path-like keys naming a DESTINATION: relativised, but outside `hash()` and never machine-dependent. */
dataset('destinationKeys', [
    'export.path' => ['export.path', 'docs/openapi.json'],
]);

/**
 * Every path-like key whose dotted spelling is the same one a diagnostic names, for the behaviour both
 * kinds share. `export.targets` is not one: a row reads `export.targets.0.path` and the diagnostics name
 * the ENTRY, `export.targets.0`, so it has tests of its own below rather than a row here — which is why
 * the guard beside this list excuses it by name and nothing else.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function pathKeyRows(): array
{
    return [
        'api_version.changes[0]' => ['api_version.changes.0', 'app/Api/Versions'],
        'content.dir' => ['content.dir', 'resources/docs/api'],
        'coverage.log' => ['coverage.log', 'storage/docuccino/coverage'],
        'examples.recordings' => ['examples.recordings', 'docs/recordings'],
        'export.path' => ['export.path', 'docs/openapi.json'],
        'info.description.file' => ['info.description.file', 'resources/docs/description.md'],
        'overlays[0]' => ['overlays.0', 'resources/docs/overlays/*.yaml'],
        'webhooks.dir' => ['webhooks.dir', 'app/Webhooks'],
    ];
}

dataset('allPathKeys', pathKeyRows());

it('covers every path-like key this class knows with a dataset row', function (): void {
    // The lists above are hand-written and PATH_KEYS is the source of truth, so a key added there and
    // forgotten here would be relativised, reported and refused with nothing proving any of it.
    /** @var array<string, mixed> $keys */
    $keys = (array) (new ReflectionClass(ConfigPaths::class))->getConstant('PATH_KEYS');
    $rows = array_values(pathKeyRows());

    expect($keys)->toHaveCount(9);

    foreach (array_keys($keys) as $key) {
        if ($key === 'export.targets') {
            continue;
        }

        $covered = array_filter($rows, static fn (array $row): bool => str_starts_with($row[0], (string) $key));

        expect($covered)->not->toBe([], 'no pathKeyRows() row covers '.$key);
    }
});

it('leaves a relative path untouched', function (string $key, string $relative): void {
    $document = documentFrom(rawWithPath($key, $relative));

    expect(readPath($document->raw, $key))->toBe($relative);
})->with('allPathKeys');

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

it('relativises a destination path but never reports it as machine-dependent', function (string $key, string $relative): void {
    // A destination says where bytes are WRITTEN. Nothing about the document depends on it, so pointing
    // it out of tree makes no output machine-dependent and there is nothing to report.
    $base = '/checkout/one';

    expect(readPath(documentFrom(rawWithPath($key, $base.'/'.$relative), $base)->raw, $key))->toBe($relative);

    foreach ([$relative, $base.'/'.$relative, '/opt/shared/'.$relative] as $configured) {
        expect(ConfigDiagnostics::for(documentFrom(rawWithPath($key, $configured), $base)))->toBe([]);
    }
})->with('destinationKeys');

it('hashes the same however a destination is spelled, and wherever it points', function (string $key, string $relative): void {
    // The whole point of taking `export` out of the hash: changing where an artifact lands must not
    // re-fingerprint the document, because that would rewrite committed bytes and cold-bust every
    // cached fragment over a filename.
    $base = '/checkout/one';
    $baseline = documentFrom([], $base)->hash();

    foreach ([$relative, $base.'/'.$relative, '/opt/shared/'.$relative, 'somewhere/else.json'] as $configured) {
        expect(documentFrom(rawWithPath($key, $configured), $base)->hash())->toBe($baseline);
    }
})->with('destinationKeys');

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

it('refuses a path no filesystem call can accept, and names the key that holds it', function (string $key, string $relative): void {
    // A NUL byte is a path no filesystem can hold, and `glob()`, `realpath()`, `file_get_contents()`,
    // `scandir()` and `mkdir()` all raise a `ValueError` on one — which `@` does not suppress. Refused
    // once at the config boundary, so no reader downstream can be handed it, and reported by key: the
    // alternative for the same input was an uncaught ValueError naming `glob()` and no config key at all.
    $document = documentFrom(rawWithPath($key, "resources/docs\0/".$relative));

    $diagnostics = array_values(array_filter(
        ConfigDiagnostics::for($document),
        static fn (Diagnostic $d): bool => $d->code === 'config.path-rejected',
    ));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($diagnostics[0]->message)->toContain($key)
        // The offending value is the author's own text on its way into a published message, so the byte
        // that got it refused is escaped rather than carried through.
        ->and($diagnostics[0]->message)->not->toContain("\0");
})->with('allPathKeys');

it('hands every reader nothing rather than a path it would raise on', function (): void {
    $document = documentFrom([
        'content' => ['dir' => "resources\0/docs"],
        'webhooks' => ['dir' => "app\0/Webhooks"],
        'examples' => ['recordings' => "docs\0/recordings"],
        'coverage' => ['log' => "storage\0/coverage"],
        'overlays' => ["resources\0/overlays/*.yaml", 'resources/ok/*.yaml'],
        'export' => ['path' => "docs\0/openapi.json"],
    ]);

    expect($document->contentDir())->toBeNull()
        ->and($document->webhooksDir())->toBeNull()
        ->and($document->recordingsDir())->toBeNull()
        ->and($document->coverageLogDir())->toBeNull()
        // The refused pattern is gone and the usable one beside it still applies — one bad entry costs
        // its own overlay, not the whole list.
        ->and($document->overlays)->toBe(['resources/ok/*.yaml'])
        // A destination falls back to the documented default, exactly as a non-string value does.
        ->and($document->exportPath())->toBe('docs/openapi.json');
});

it('reports an unusable export target as the shape problem it is, and by key', function (): void {
    // `export.targets` already refuses to build on a malformed entry, and a path holding a NUL is as
    // unusable as an absent one — so it reports there rather than through a mechanism of its own. The
    // config diagnostic names it too, by ENTRY, which is the key the machine-dependent report uses.
    $document = documentFrom(['export' => ['targets' => [['format' => 'openapi-3.2', 'path' => "docs\0.json"]]]]);

    $rejected = array_values(array_filter(
        ConfigDiagnostics::for($document),
        static fn (Diagnostic $d): bool => $d->code === 'config.path-rejected',
    ));

    expect($document->exportTargetIssues())->toContain(['index' => 0, 'problem' => 'shape', 'detail' => ''])
        ->and(array_map(static fn ($t): string => $t->path, $document->exportTargets()))->toBe(['docs/openapi.json'])
        ->and($rejected)->toHaveCount(1)
        ->and($rejected[0]->message)->toContain('export.targets.0');
});

it('keeps the refused value in the raw bag, so the configHash still describes what was configured', function (): void {
    // Dropping it from `raw` would move every emitted `configHash` for a config nobody can read anyway,
    // and would leave the reporter with nothing to name.
    $document = documentFrom(['content' => ['dir' => "resources\0/docs"]]);

    expect(readPath($document->raw, 'content.dir'))->toBe("resources\0/docs")
        ->and(ConfigPaths::unholdable($document->raw))->toBe([['key' => 'content.dir', 'path' => "resources\0/docs"]]);
});

it('says nothing about a path it can hold', function (string $key, string $relative): void {
    expect(array_filter(
        ConfigDiagnostics::for(documentFrom(rawWithPath($key, $relative))),
        static fn (Diagnostic $d): bool => $d->code === 'config.path-rejected',
    ))->toBe([]);
})->with('allPathKeys');

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
