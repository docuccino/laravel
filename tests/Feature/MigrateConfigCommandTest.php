<?php

declare(strict_types=1);

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Support\Json;
use Docuccino\Laravel\Config\ConfigMigration;
use Docuccino\Laravel\Config\ConfigSplit;
use Docuccino\Laravel\Tests\Support\BuildSettings;
use Docuccino\Laravel\Tests\Support\FrameworkConfig;
use Illuminate\Support\Facades\Artisan;

/**
 * `docuccino:migrate-config` — the way out of `config.not-migrated`, which refuses a build whose
 * settings are all still in `config/docuccino.php`.
 *
 * The guard that carries this file is the round-trip: the settings parsed back out of the YAML it
 * writes are compared, by fingerprint, against the build subset of the framework config written out
 * BY HAND below. Hand-written on purpose — the transform's own tables would agree with whatever the
 * transform did, and a migration that dropped half an application's configuration would pass a test
 * that asked the code what it had meant to drop.
 *
 * Everything else here is a degradation: no framework config at all, one holding nothing, one holding
 * only what the framework keeps, a closure that has no form in a configuration file, a
 * `docuccino.yaml` already there, and a target that cannot be written.
 */

/**
 * The build half of {@see FrameworkConfig::populated()}, as `docuccino.yaml` has to hold it. Written out
 * rather than derived: this is the independent statement of the property, and deriving it would make
 * the round-trip agree with the writer by construction.
 *
 * Three settings differ from the framework config on purpose. `security.auth_middleware` and
 * `engine.config` are the same settings under the names they have now, and writing the old spellings
 * would hand back a file the build reports as naming no setting. `routes.closure` and
 * `representation.lists` are gone from the product, so there is no key to write them under.
 *
 * @return array<string, mixed>
 */
function migratedBuildSettings(): array
{
    return [
        'documents' => [
            'default' => [
                'info' => ['title' => 'Billing API', 'version' => '3.1.4'],
                'servers' => [['url' => 'https://api.example.com', 'description' => 'Production']],
                'routes' => [
                    'include' => ['api/v2/*', 'api/v3/*'],
                    'exclude' => ['api/v2/internal/*'],
                    'include_vendor' => true,
                ],
                'security' => [
                    'auth_middleware' => 'auth:sanctum*',
                    'schemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
                    'default' => [['bearer' => []]],
                ],
                'error_responses' => 'none',
                'tags' => ['default_strategy' => 'none', 'map' => ['Invoice' => 'Billing']],
                'content' => ['dir' => 'resources/docs/api'],
                'overlays' => ['resources/docs/overlays/*.yaml'],
                'representation' => [
                    'filters' => 'deepObject',
                    'nullable' => 'anyof',
                    'operation_id' => 'controller-method',
                ],
                'versioning' => 'semver',
                'integrations' => ['permission' => ['enabled' => true], 'eloquent' => ['enabled' => false]],
                'export' => ['path' => 'docs/billing.json'],
            ],
            'public' => [],
        ],
        'extensions' => ['App\Docs\InvoiceTotalsExtension'],
        'lint' => [
            'leakage' => ['enabled' => true, 'allow' => ['reset_token']],
            'descriptions' => ['enabled' => true, 'allow' => []],
        ],
        'diagnostics' => ['accept' => ['eloquent.no-columns']],
        'engine' => [
            'mode' => 'in-process',
            'project_paths' => ['app', 'modules'],
            'config' => 'phpstan.neon',
        ],
        'on_route_error' => 'omit',
        'cache' => ['enabled' => true, 'path' => 'storage/docs/fragments'],
    ];
}

/** The settings the migration would write, read back through the reader a real project's file goes through. */
function migratedSettings(): array
{
    $file = ConfigFile::parse(ConfigMigration::of()->file());

    expect($file->error)->toBeNull('the migration wrote a file the reader refuses');

    return $file->values;
}

/** An application whose build settings are all still in the framework config. */
function arrangePopulatedFrameworkConfig(): void
{
    BuildSettings::none();
    config()->set('docuccino', FrameworkConfig::populated());
}

/**
 * A base path this test owns, so a command that writes `docuccino.yaml` at the project ROOT does not
 * write it into the workbench that every parallel process shares.
 */
function ownedBasePath(): string
{
    $directory = sys_get_temp_dir().'/docuccino-migrate-'.bin2hex(random_bytes(8));
    mkdir($directory, 0777, true);
    app()->setBasePath($directory);

    return $directory;
}

function forgetBasePath(string $directory): void
{
    @unlink($directory.'/'.ConfigFile::NAME);
    @rmdir($directory);
}

/**
 * Write a `config/docuccino.php` under the owned root, holding `$body` as its source text.
 *
 * The migration reads that file as TEXT as well as through `config()`, because whether a setting came
 * through `env()` is a question about what was WRITTEN and a resolved value carries no trace of it.
 */
function writeFrameworkConfigSource(string $directory, string $body): void
{
    mkdir($directory.'/config', 0777, true);
    file_put_contents($directory.'/config/docuccino.php', "<?php\n\nreturn ".$body.";\n");
}

function forgetFrameworkConfigSource(string $directory): void
{
    @unlink($directory.'/config/docuccino.php');
    @rmdir($directory.'/config');
}

/**
 * Run the command and hand back [exit code, everything it printed].
 *
 * Output is read off the real buffer rather than through `expectsOutputToContain`, whose expectations
 * are one per written LINE: two substrings of the same line satisfy one expectation between them, and
 * this command reports several facts per line on purpose.
 *
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function runMigrateConfig(array $arguments = []): array
{
    test()->withoutMockingConsoleOutput();

    $code = test()->artisan('docuccino:migrate-config', $arguments);

    return [$code, Artisan::output()];
}

// --- The round trip ------------------------------------------------------------------------------

it('writes back every build setting the framework config held, and nothing the framework keeps', function (): void {
    // The guard. Both sides go through `Json::stable()`, which is order-insensitive, so this states
    // that the two hold the same settings and says nothing about the order they are written in.
    arrangePopulatedFrameworkConfig();

    expect(Json::stable(migratedSettings()))->toBe(Json::stable(migratedBuildSettings()));
});

it('fingerprints the migrated file to the build subset it came from', function (): void {
    // The same property as one hash each, which is the form the fingerprint is compared in everywhere
    // else — a document's `configHash` is this over the bag that shapes it.
    arrangePopulatedFrameworkConfig();

    expect(hash('sha256', Json::stable(migratedSettings())))
        ->toBe(hash('sha256', Json::stable(migratedBuildSettings())));
});

it('states the round trip over a configuration worth round-tripping', function (): void {
    // A hand-written expectation that had quietly shrunk to nothing would let the two assertions above
    // pass on an empty file. Counted here rather than asserted there, so the guard stays one line.
    arrangePopulatedFrameworkConfig();

    $settings = migratedSettings();

    expect(count($settings, COUNT_RECURSIVE))->toBeGreaterThan(60)
        ->and(array_keys($settings))->toContain('documents', 'lint', 'engine', 'cache', 'extensions')
        ->and($settings['documents'])->toHaveKeys(['default', 'public']);
});

it('leaves every framework-owned key behind', function (): void {
    // The other half of the split, from this side: a key the framework reads at boot must not travel,
    // because `docuccino.yaml` declares no setting of that name and the build would report each one.
    arrangePopulatedFrameworkConfig();

    $settings = migratedSettings();

    expect($settings)->not->toHaveKey('enabled')
        ->and($settings['cache'])->not->toHaveKey('store')
        ->and($settings['documents']['default'])->not->toHaveKey('viewer')
        ->and($settings['documents']['public'])->toBe([]);
});

it('carries a document that declares nothing but a viewer', function (): void {
    // Its whole bag belongs to the framework, so it contributes no stray key at all — and dropping the
    // KEY would delete a document whose viewer is registered and would then serve nothing.
    arrangePopulatedFrameworkConfig();

    expect(array_keys(migratedSettings()['documents']))->toBe(['default', 'public']);
});

it('applies each rename rather than writing a spelling nothing reads', function (): void {
    arrangePopulatedFrameworkConfig();

    $settings = migratedSettings();

    expect($settings['documents']['default']['security'])->toHaveKey('auth_middleware')
        ->and($settings['documents']['default']['security'])->not->toHaveKey('auto_detect_middleware')
        ->and($settings['engine'])->toHaveKey('config')
        ->and($settings['engine'])->not->toHaveKey('neon')
        ->and($settings['engine']['config'])->toBe('phpstan.neon');
});

it('records each rename on the migration itself', function (): void {
    arrangePopulatedFrameworkConfig();

    expect(ConfigMigration::of()->renamed)->toBe([
        'documents.default.security.auto_detect_middleware' => 'documents.default.security.auth_middleware',
        'engine.neon' => 'engine.config',
    ]);
});

it('writes a file the reader accepts as a map of settings', function (): void {
    // `docuccino.yaml` holding anything but a map is one of the four states that refuse a build, so a
    // migration that produced one would replace the error it was run to clear with another.
    arrangePopulatedFrameworkConfig();

    $file = ConfigFile::parse(ConfigMigration::of()->file());

    expect($file->error)->toBeNull()
        ->and($file->diagnostics)->toBe([])
        ->and($file->ok())->toBeTrue();
});

it('writes a numeric-looking string back as the string it was', function (): void {
    // The fidelity trap in writing YAML from PHP values. `info.version: '1.0'` unquoted parses as the
    // float 1.0, which the reader then settles to the int 1 — so a version would arrive at the
    // document as a number, and `'2'` as one too. The writer has to quote what would otherwise read
    // as a number, and this is the assertion that it does.
    arrangePopulatedFrameworkConfig();
    config()->set('docuccino.documents.default.info.version', '1.0');
    config()->set('docuccino.documents.default.info.title', '2');

    $info = migratedSettings()['documents']['default']['info'];

    expect($info['version'])->toBe('1.0')
        ->and($info['title'])->toBe('2')
        ->and($info['version'])->toBeString()
        ->and($info['title'])->toBeString();
});

it('writes a value the reader would have refused nowhere at all', function (): void {
    // Every state `ConfigFile` reports on is a state this must not create. A migration whose output
    // carried a diagnostic would replace the error it cleared with a warning of its own.
    arrangePopulatedFrameworkConfig();
    config()->set('docuccino.documents.default.info.description', "two\nlines\n");
    config()->set('docuccino.documents.default.tags.map', ['A: b' => 'c #d', 'true' => 'no']);

    $file = ConfigFile::parse(ConfigMigration::of()->file());

    expect($file->diagnostics)->toBe([])
        ->and($file->values['documents']['default']['info']['description'])->toBe("two\nlines\n")
        ->and($file->values['documents']['default']['tags']['map'])->toBe(['A: b' => 'c #d', 'true' => 'no']);
});

it('writes the same bytes twice', function (): void {
    arrangePopulatedFrameworkConfig();

    expect(ConfigMigration::of()->file())->toBe(ConfigMigration::of()->file());
});

// --- What it says on the way past ----------------------------------------------------------------

it('names what to delete, and does not delete it', function (): void {
    // Rewriting `config/docuccino.php` is the act this command does not perform: the framework keeps
    // three of its keys, so it would be surgery rather than a replacement, and the comments and env()
    // calls in it cannot be put back from a parsed array. So the deletion is named and left.
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();

    try {
        [$code, $output] = runMigrateConfig();

        expect($code)->toBe(0)
            ->and($output)->toContain('Delete the migrated settings from config/docuccino.php')
            ->and($output)->toContain('documents.default.routes')
            ->and($output)->toContain('on_route_error')
            // The framework config is untouched, every key still where its author wrote it.
            ->and(config('docuccino.documents.default.info.title'))->toBe('Billing API')
            ->and(config('docuccino.on_route_error'))->toBe('omit');
    } finally {
        forgetBasePath($directory);
    }
});

it('writes the file at the project root', function (): void {
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();

    try {
        [$code, $output] = runMigrateConfig();

        expect($code)->toBe(0)
            ->and($output)->toContain('Wrote docuccino.yaml')
            ->and(is_file($directory.'/'.ConfigFile::NAME))->toBeTrue()
            ->and((string) file_get_contents($directory.'/'.ConfigFile::NAME))
            ->toBe(ConfigMigration::of()->file());
    } finally {
        forgetBasePath($directory);
    }
});

it('reports each rename it applied', function (): void {
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();

    try {
        [, $output] = runMigrateConfig();

        expect($output)->toContain('Renamed on the way over')
            ->and($output)->toContain('security.auto_detect_middleware')
            ->and($output)->toContain('security.auth_middleware')
            ->and($output)->toContain('engine.neon')
            ->and($output)->toContain('engine.config');
    } finally {
        forgetBasePath($directory);
    }
});

it('says which settings shaped nothing on their way out', function (): void {
    // `representation.lists` had no reader, and `routes.closure` is null here — nothing was filtering.
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();

    try {
        [$code, $output] = runMigrateConfig();

        expect($code)->toBe(0)
            ->and($output)->toContain('Dropped, with the document unchanged')
            ->and($output)->toContain('documents.default.representation.lists')
            ->and($output)->toContain('documents.default.routes.closure');
    } finally {
        forgetBasePath($directory);
    }
});

it('reads a null closure as nothing lost', function (): void {
    // The firing population matters more than the wording: `closure => null` is what the shipped
    // framework config carried, so an application that never wrote a filter has one. Reporting a loss
    // there would fire the loudest line this command has on nearly every migration there is.
    arrangePopulatedFrameworkConfig();

    $migration = ConfigMigration::of();

    expect($migration->lost)->toBe([])
        ->and($migration->complete())->toBeTrue()
        ->and($migration->dropped)->toContain('documents.default.routes.closure');
});

it('names which values this environment decided, and what still overrides them', function (): void {
    // `config()` hands over RESOLVED values, so an `env()` call in the framework config arrived as
    // whatever the variable said here. `DOCUCCINO_ENGINE` is one of the two the tool itself put behind
    // a variable, which is what makes its spelling known and this claim checkable.
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();
    writeFrameworkConfigSource($directory, "['engine' => ['mode' => env('DOCUCCINO_ENGINE', 'in-process')]]");
    putenv('DOCUCCINO_ENGINE=null');

    try {
        [, $output] = runMigrateConfig();

        expect($output)->toContain('Read from this environment rather than from the file')
            ->and($output)->toContain('engine.mode')
            ->and($output)->toContain('DOCUCCINO_ENGINE')
            ->and($output)->toContain('still overrides this file')
            // And in the file, where it outlives the console.
            ->and((string) file_get_contents($directory.'/'.ConfigFile::NAME))
            ->toContain('engine.mode was read from DOCUCCINO_ENGINE');
    } finally {
        putenv('DOCUCCINO_ENGINE');
        forgetFrameworkConfigSource($directory);
        forgetBasePath($directory);
    }
});

it('says nothing about an environment that set nothing', function (): void {
    // The other side of the same report. A variable the framework config reads but nobody set resolved
    // to that file's own fallback, so nothing was decided elsewhere and there is nothing to say — and
    // saying it anyway would put a line about nothing on nearly every migration there is.
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();
    writeFrameworkConfigSource($directory, "['engine' => ['mode' => env('DOCUCCINO_ENGINE', 'in-process')]]");

    try {
        putenv('DOCUCCINO_ENGINE');

        expect(ConfigMigration::of()->environment)->toBe([]);
    } finally {
        forgetFrameworkConfigSource($directory);
        forgetBasePath($directory);
    }
});

it('does not claim a literal came from an environment that never touched it', function (): void {
    // The check that keeps the report honest. A resolved value looks the same whether an `env()` call
    // produced it or an author typed it, so with the variable SET and the framework config not naming
    // it, the only truthful answer is silence — the loud one would tell an author their own literal
    // was this machine's doing.
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();
    writeFrameworkConfigSource($directory, "['engine' => ['mode' => 'in-process']]");
    putenv('DOCUCCINO_ENGINE=null');

    try {
        expect(ConfigMigration::of()->environment)->toBe([])
            ->and(ConfigMigration::of()->resolved)->toBeFalse();
    } finally {
        putenv('DOCUCCINO_ENGINE');
        forgetFrameworkConfigSource($directory);
        forgetBasePath($directory);
    }
});

it('says that resolved values are resolved, whichever keys they were', function (): void {
    // Which keys an application put an `env()` call on cannot be answered from a parsed array, so the
    // report says the calls exist and where to check rather than naming keys it would be guessing at.
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();
    writeFrameworkConfigSource($directory, "['export' => ['path' => env('DOCS_PATH', 'docs/openapi.json')]]");

    try {
        [, $output] = runMigrateConfig();

        expect(ConfigMigration::of()->resolved)->toBeTrue()
            ->and($output)->toContain('config/docuccino.php reads settings through env()')
            ->and($output)->toContain('written above as a literal');
    } finally {
        forgetFrameworkConfigSource($directory);
        forgetBasePath($directory);
    }
});

it('migrates an application whose framework config has already been deleted', function (): void {
    // The source text is read for one question only, so losing it degrades that answer and nothing
    // else: `config()` still holds the settings, and the file still gets written.
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();

    try {
        [$code] = runMigrateConfig();

        expect($code)->toBe(0)
            ->and(ConfigMigration::of()->resolved)->toBeFalse()
            ->and(ConfigMigration::of()->environment)->toBe([])
            ->and(is_file($directory.'/'.ConfigFile::NAME))->toBeTrue();
    } finally {
        forgetBasePath($directory);
    }
});

// --- Degradations ---------------------------------------------------------------------------------

it('refuses to leave a closure behind quietly', function (): void {
    // A closure has no form in YAML and the routes it held back are documented again, so the file is
    // written, the omission is named, and the exit code says the migration is not finished. A silent
    // drop here is the confidently-wrong document `config.not-migrated` is an error to prevent.
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();
    config()->set('docuccino.documents.default.routes.closure', static fn (): bool => true);

    try {
        [$code, $output] = runMigrateConfig();
        $written = (string) file_get_contents($directory.'/'.ConfigFile::NAME);

        expect($code)->toBe(1)
            ->and($output)->toContain('documents.default.routes.closure was NOT carried over')
            ->and($output)->toContain('a closure has no form in a configuration file')
            ->and($output)->toContain('implement RouteFilter and set routes.filter')
            // Written all the same: everything else it carried is work its author would otherwise redo.
            ->and($written)->toContain('NOT carried over: documents.default.routes.closure')
            ->and($written)->toContain('the routes it held back are documented again')
            // The note is a COMMENT and never a key: a placeholder uncommented by hand would join the
            // resolved configuration and change the document's fingerprint for a setting nobody set.
            ->and(ConfigFile::parse($written)->values['documents']['default']['routes'])
            ->not->toHaveKey('closure');
    } finally {
        forgetBasePath($directory);
    }
});

it('writes nothing when there is no framework config at all', function (): void {
    $directory = ownedBasePath();
    config()->set('docuccino', []);

    try {
        [$code, $output] = runMigrateConfig();

        expect($code)->toBe(0)
            ->and($output)->toContain('config/docuccino.php holds no build settings')
            ->and($output)->toContain('docuccino:install')
            // An empty `docuccino.yaml` parses to nothing, which is itself one of the states that
            // refuse a build — so writing one here would replace an absence with an error.
            ->and(is_file($directory.'/'.ConfigFile::NAME))->toBeFalse();
    } finally {
        forgetBasePath($directory);
    }
});

it('writes nothing for a framework config holding only what the framework keeps', function (): void {
    $directory = ownedBasePath();
    config()->set('docuccino', [
        'enabled' => true,
        'documents' => ['default' => ['viewer' => ['route' => '/docs/api']]],
        'cache' => ['store' => 'redis'],
    ]);

    try {
        [$code, $output] = runMigrateConfig();

        expect($code)->toBe(0)
            ->and($output)->toContain('holds no build settings, so there is nothing to migrate')
            ->and(is_file($directory.'/'.ConfigFile::NAME))->toBeFalse();
    } finally {
        forgetBasePath($directory);
    }
});

it('says nothing about install when a configuration file is already there', function (): void {
    // Nothing to migrate AND a file at the root is a migration somebody finished. Pointing them at
    // `docuccino:install` there would be advice to publish defaults over it.
    $directory = ownedBasePath();
    config()->set('docuccino', ['enabled' => true]);
    file_put_contents($directory.'/'.ConfigFile::NAME, "documents:\n  default: {}\n");

    try {
        [$code, $output] = runMigrateConfig();

        expect($code)->toBe(0)
            ->and($output)->toContain('already holds this project')
            ->and($output)->not->toContain('docuccino:install');
    } finally {
        forgetBasePath($directory);
    }
});

it('never replaces a docuccino.yaml somebody already wrote', function (): void {
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();
    file_put_contents($directory.'/'.ConfigFile::NAME, "documents:\n  mine: {}\n");

    try {
        [$code, $output] = runMigrateConfig();

        expect($code)->toBe(0)
            ->and($output)->toContain('is already there, and was left exactly as it is')
            ->and($output)->toContain('Pass --force')
            ->and($output)->toContain('replaces the file rather than merging into it')
            ->and((string) file_get_contents($directory.'/'.ConfigFile::NAME))
            ->toBe("documents:\n  mine: {}\n");
    } finally {
        forgetBasePath($directory);
    }
});

it('replaces it only when --force asks for it', function (): void {
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();
    file_put_contents($directory.'/'.ConfigFile::NAME, "documents:\n  mine: {}\n");

    try {
        [$code] = runMigrateConfig(['--force' => true]);

        expect($code)->toBe(0)
            ->and((string) file_get_contents($directory.'/'.ConfigFile::NAME))
            ->toBe(ConfigMigration::of()->file());
    } finally {
        forgetBasePath($directory);
    }
});

it('runs twice with the same result and the same file', function (): void {
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();

    try {
        [$first] = runMigrateConfig();
        $written = (string) file_get_contents($directory.'/'.ConfigFile::NAME);

        [$second] = runMigrateConfig(['--force' => true]);

        expect($first)->toBe(0)
            ->and($second)->toBe(0)
            ->and((string) file_get_contents($directory.'/'.ConfigFile::NAME))->toBe($written);
    } finally {
        forgetBasePath($directory);
    }
});

it('prints the file it would write and writes nothing', function (): void {
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();

    try {
        [$code, $output] = runMigrateConfig(['--dry-run' => true]);

        expect($code)->toBe(0)
            ->and($output)->toContain('as it would be written')
            ->and($output)->toContain('Billing API')
            ->and($output)->toContain('auth_middleware')
            ->and(is_file($directory.'/'.ConfigFile::NAME))->toBeFalse();
    } finally {
        forgetBasePath($directory);
    }
});

it('says a lost setting on a dry run too', function (): void {
    // The dry run is the same report over the same file, so it owes the same exit code: a script that
    // checked a migration before making it would otherwise read 0 and go ahead.
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();
    config()->set('docuccino.documents.default.routes.closure', static fn (): bool => true);

    try {
        [$code, $output] = runMigrateConfig(['--dry-run' => true]);

        expect($code)->toBe(1)
            ->and($output)->toContain('was NOT carried over')
            ->and(is_file($directory.'/'.ConfigFile::NAME))->toBeFalse();
    } finally {
        forgetBasePath($directory);
    }
});

it('says so when the file cannot be written', function (): void {
    // A root that cannot hold a file at all, which is the one write failure a test can arrange without
    // depending on who it runs as.
    app()->setBasePath('/dev/null/docuccino');
    arrangePopulatedFrameworkConfig();

    [$code, $output] = runMigrateConfig();

    expect($code)->toBe(1)
        ->and($output)->toContain('Could not write');
});

it('refuses to run while Docuccino is disabled', function (): void {
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();
    config()->set('docuccino.enabled', false);

    try {
        [$code, $output] = runMigrateConfig();

        expect($code)->toBe(1)
            ->and($output)->toContain('Docuccino is disabled')
            ->and(is_file($directory.'/'.ConfigFile::NAME))->toBeFalse();
    } finally {
        forgetBasePath($directory);
    }
});

// --- A value the file has no form for -------------------------------------------------------------

/**
 * A backed enum on a build setting, which is how modern Laravel configuration is written.
 */
enum MigrateQaDriver: string
{
    case Scalar = 'scalar';
}

it('drops a value docuccino.yaml has no form for, names it, and writes a file the build reads', function (string $path, mixed $value): void {
    // The defect this closes. `YamlSerializer` writes an enum as `!php/enum`, which `ConfigFile::FLAGS`
    // is deliberately set to REFUSE, and a closure, a resource or a date as something else entirely —
    // so the command reported success over a file the very next build either refuses or reads wrong.
    // Dropped and named is the only honest answer: an absent key is a setting nobody expressed.
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();
    config()->set('docuccino.'.$path, $value);

    try {
        [$code, $output] = runMigrateConfig();
        $written = (string) file_get_contents($directory.'/'.ConfigFile::NAME);
        $file = ConfigFile::parse($written);

        expect($code)->toBe(1)
            ->and($output)->toContain($path.' was NOT carried over')
            ->and($output)->toContain('has no form for the value written there')
            // And in the file, where it outlives the console this command is run once in.
            ->and($written)->toContain('NOT carried over: '.$path)
            // The file the build reads next: it parses, it says nothing, and the key is simply not there.
            ->and($file->error)->toBeNull()
            ->and($file->diagnostics)->toBe([])
            ->and(ConfigMigration::of()->unreadable())->toBeNull();
    } finally {
        forgetBasePath($directory);
    }
})->with([
    // Refused outright by the reader — the file lands and the next build reports config.file-invalid.
    'an enum case' => ['documents.default.versioning', MigrateQaDriver::Scalar],
    // Written as `null`, which the reader accepts as an empty value the author never wrote.
    'a resource' => ['documents.default.export.path', STDOUT],
    // Written as a timestamp, which the reader hands back as an integer.
    'a date' => ['documents.default.info.version', new DateTimeImmutable('2020-01-01')],
    // Read with a diagnostic and settled to null, so the file warns on every build from here on.
    'a value that is not a number' => ['documents.default.representation.filters', NAN],
]);

it('drops a closure at a key that is not routes.closure', function (): void {
    // The same defect with a closure, which no dataset can carry. `routes.closure` has its own report
    // because the key is gone from the product; a closure anywhere else is a value with no form, and
    // used to be written as `mapper: null` with the migration reporting nothing lost at all.
    $directory = ownedBasePath();
    arrangePopulatedFrameworkConfig();
    config()->set('docuccino.documents.default.tags.mapper', static fn (string $tag): string => $tag);

    try {
        [$code, $output] = runMigrateConfig();
        $written = (string) file_get_contents($directory.'/'.ConfigFile::NAME);

        expect($code)->toBe(1)
            ->and($output)->toContain('documents.default.tags.mapper was NOT carried over')
            // Named in the comment header and written as a key nowhere: it used to arrive as
            // `mapper: null`, with the migration reporting nothing lost at all.
            ->and($written)->not->toContain('mapper: ')
            ->and(ConfigFile::parse($written)->values['documents']['default']['tags'])
            ->not->toHaveKey('mapper');
    } finally {
        forgetBasePath($directory);
    }
});

it('writes a file the reader gives back unchanged, whatever an application configured', function (mixed $value): void {
    // Stated over arbitrary input rather than over the one corpus above, because that corpus is the
    // input the transform was written against. The property is the whole guarantee: the settings the
    // reader hands back are the settings the migration says it wrote.
    arrangePopulatedFrameworkConfig();
    config()->set('docuccino.documents.default.info.title', $value);

    $migration = ConfigMigration::of();
    $file = ConfigFile::parse($migration->file());

    expect($migration->unreadable())->toBeNull()
        ->and($file->error)->toBeNull()
        ->and($file->diagnostics)->toBe([])
        ->and(Json::stable($file->values))->toBe(Json::stable($migration->settings));
})->with([
    'a string that looks like a number' => ['1.10'],
    'a string that looks like a boolean' => ['no'],
    'a string that looks like nothing' => ['null'],
    'a string of YAML' => ["a: b\n#c"],
    'a string with an escape sequence' => ["red\x1b[31m"],
    'a multi-line string' => ["two\nlines\n"],
    'a string of invalid UTF-8' => ["a\xC3("],
    'an integral float' => [1.0],
    'an enum case' => [MigrateQaDriver::Scalar],
    'a date' => [new DateTimeImmutable('2020-01-01')],
    'an infinity' => [INF],
    'a list holding an object' => [['a', new DateTimeImmutable('2020-01-01')]],
    'a map an author keyed themselves' => [['A: b' => 'c #d', "e\nf" => 'g']],
]);

it('keeps a document key with a newline inside the comment it belongs to', function (): void {
    // A document key is an application's own word and it lands on a COMMENT line. A newline in one
    // ends the comment, so everything after it became settings nobody wrote — and the file still
    // parsed, so nothing downstream would have noticed.
    BuildSettings::none();
    config()->set('docuccino', ['documents' => [
        "evil\nversioning: semver\n#" => ['routes' => ['closure' => static fn (): bool => true]],
    ]]);

    $migration = ConfigMigration::of();
    $written = $migration->file();
    $file = ConfigFile::parse($written);

    // The report is still made, and the key is still named — escaped, on one line.
    expect($migration->lost)->toHaveCount(1)
        ->and($written)->toContain('NOT carried over: documents.evil\x0Aversioning: semver\x0A#.routes.closure')
        // Every comment line is still a comment, and no setting appeared that nobody set.
        ->and($file->error)->toBeNull()
        ->and($file->values)->not->toHaveKey('versioning')
        ->and(array_keys($file->values))->toBe(['documents'])
        ->and($migration->unreadable())->toBeNull();
});

it('writes nothing at all when the settings do not survive being written', function (): void {
    // The backstop, and it is not a formality: a top-level key an application spelled numerically makes
    // the whole file a LIST, which the reader refuses — a plausible file, and not a value question, so
    // nothing above catches it. The next thing this command prints is "delete config/docuccino.php",
    // which after a bad migration is the author's only surviving copy of these settings.
    $directory = ownedBasePath();
    BuildSettings::none();
    config()->set('docuccino', ['enabled' => true, 0 => 'stray']);

    try {
        [$code, $output] = runMigrateConfig();

        expect($code)->toBe(1)
            ->and($output)->toContain('Could not write docuccino.yaml')
            ->and($output)->toContain('must hold a map of settings')
            ->and($output)->toContain('config/docuccino.php was not touched')
            // Not named, because there is nothing to delete yet.
            ->and($output)->not->toContain('Delete the migrated settings')
            ->and(is_file($directory.'/'.ConfigFile::NAME))->toBeFalse();
    } finally {
        forgetBasePath($directory);
    }
});

it('shows the file it could not write, and still writes nothing', function (): void {
    // A dry run is asked what WOULD happen, so it prints the file and then says why it would not land.
    $directory = ownedBasePath();
    BuildSettings::none();
    config()->set('docuccino', ['enabled' => true, 0 => 'stray']);

    try {
        [$code, $output] = runMigrateConfig(['--dry-run' => true]);

        expect($code)->toBe(1)
            ->and($output)->toContain('as it would be written')
            ->and($output)->toContain('Could not write docuccino.yaml')
            ->and(is_file($directory.'/'.ConfigFile::NAME))->toBeFalse();
    } finally {
        forgetBasePath($directory);
    }
});

it('names the reader\'s own complaint about a file it would not have written', function (): void {
    // The same guarantee stated against a bag handed in rather than against an application, which is
    // what makes it a property of the transform: the reader is asked, and its answer is what the
    // command prints. Both directions, so a check that always answered null would fail here.
    expect(ConfigMigration::from(['0' => 'stray'])->unreadable())
        ->toContain('must hold a map of settings')
        ->and(ConfigMigration::from(['on_route_error' => 'omit'])->unreadable())->toBeNull();
});

// --- The split's two derivations ------------------------------------------------------------------

it('reads the same split for the paths it names and the settings it writes', function (): void {
    // `staleKeys()` names what to delete and `buildSettings()` writes what to keep, and they are two
    // readings of one rule. Stated independently of both: the paths of the nested bag, at the depth
    // the split is decided, minus the document keys that carry no build setting of their own.
    arrangePopulatedFrameworkConfig();

    $paths = [];
    foreach (ConfigSplit::buildSettings() as $key => $value) {
        if ($key !== 'cache' && $key !== 'documents') {
            $paths[] = $key;

            continue;
        }

        foreach ((array) $value as $inner => $member) {
            if ($key === 'cache') {
                $paths[] = 'cache.'.$inner;

                continue;
            }

            foreach (array_keys((array) $member) as $leaf) {
                $paths[] = 'documents.'.$inner.'.'.$leaf;
            }
        }
    }

    sort($paths, SORT_STRING);

    expect($paths)->toBe(ConfigSplit::staleKeys())
        ->and($paths)->not->toBeEmpty();
});
