<?php

declare(strict_types=1);

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Laravel\Commands\RefusesUnreadConfig;
use Docuccino\Laravel\Config\BuildConfig;
use Docuccino\Laravel\DocuccinoServiceProvider;
use Docuccino\Laravel\Tests\Support\BuildSettings;
use Illuminate\Container\Container;
use Spatie\LaravelPackageTools\Package;

/**
 * A configuration somebody wrote that the build could not read: the commands stop, before the build,
 * whatever `--fail-on` asks for.
 *
 * Four ways in, and they are one class rather than four cases — no `docuccino.yaml` while the build
 * settings sit in `config/docuccino.php` (`config.not-migrated`), or the file there and unreadable,
 * not YAML, or not a map of settings (the three `config.file-*` errors). Every one leaves the document
 * assembled from defaults instead of from what its author wrote, so an artifact written out of it is
 * wrong and about to be committed.
 *
 * `--fail-on` is a gate over what a build FOUND, so its quietest setting is `none` and it is also the
 * default: an error raised from inside the build printed and still exited 0, after a full analysis,
 * having written that artifact. {@see ExportDiagnostics} already refuses a document that cannot say
 * where its artifacts go on exactly those terms, and this follows it rather than inventing a second
 * status.
 *
 * Every registered command carries a ROW here, refusing or not, and the rows are held to the
 * provider's own registration — the two guards would otherwise cover their two subsets and say
 * nothing about a command added to neither.
 */

/** @return list<class-string> Every command the provider registers. */
function registeredDocuccinoCommands(): array
{
    $package = new Package;
    (new DocuccinoServiceProvider(new Container))->configurePackage($package);

    /** @var list<class-string> $commands */
    $commands = $package->commands;

    return $commands;
}

/**
 * Command class => whether it must refuse, how it is invoked, and the code it exits with on an
 * unmigrated application. Written out rather than read off the traits, because a guard that asked the
 * commands which of them refuse would agree with any answer.
 *
 * Refusing and exiting non-zero are not the same fact, which is why the code is a column of its own:
 * `docuccino:install` raises no refusal here — it is what writes the file this state is about — and
 * still exits 1, because the application it was asked to set up is not set up.
 *
 * @return array<string, array{class-string, bool, array<string, mixed>, int}>
 */
function unreadConfigRefusalRows(): array
{
    return [
        // Everything that reads the configuration to produce or check a document.
        'export' => ['Docuccino\Laravel\Commands\ExportCommand', true, ['--format' => 'uir'], 1],
        'validate' => ['Docuccino\Laravel\Commands\ValidateCommand', true, [], 1],
        'cache' => ['Docuccino\Laravel\Commands\CacheCommand', true, [], 1],
        'diff' => ['Docuccino\Laravel\Commands\DiffCommand', true, ['old' => 'docs/openapi.json'], 1],
        'coverage' => ['Docuccino\Laravel\Commands\CoverageCommand', true, [], 1],
        'explain' => ['Docuccino\Laravel\Commands\ExplainCommand', true, ['route' => 'GET /api/forms'], 1],
        'version-changes' => ['Docuccino\Laravel\Commands\VersionChangesCommand', true, ['old' => 'docs/openapi.json'], 1],
        'watch' => ['Docuccino\Laravel\Commands\WatchCommand', true, [], 1],
        // The way out: it writes the file this refusal is about, and reports the settings still sitting
        // in the framework config. Refusing to run it would leave the reader with nothing to run.
        'install' => ['Docuccino\Laravel\Commands\InstallCommand', false, ['--no-export' => true], 1],
        // Reads no configuration. Emptying a cache built from the wrong settings is the one thing
        // still worth doing here, so it is not gated on fixing them.
        'clear' => ['Docuccino\Laravel\Commands\ClearCommand', false, [], 0],
    ];
}

/** No `docuccino.yaml`, and two build settings left behind in the framework config. */
function arrangeUnmigrated(): void
{
    BuildSettings::none();
    config()->set('docuccino.documents.default.routes.include', ['api/*']);
    config()->set('docuccino.on_route_error', 'omit');
}

/**
 * Every state the refusal covers, as the code it reports. One class, four ways in — and the class is
 * what the fix is sized to: closing only the reported one would leave three siblings that each arrive
 * as their own report, with the same exit 0 and the same wrong artifact behind it.
 *
 * @return array<string, array{Closure, string}>
 */
function unreadConfigStates(): array
{
    return [
        'build settings left in the framework config' => [arrangeUnmigrated(...), 'config.not-migrated'],
        'a file that is not YAML' => [function (): void {
            BuildSettings::yaml("documents:\n\tdefault: {}\n");
        }, 'config.file-invalid'],
        'a file holding a list' => [function (): void {
            BuildSettings::yaml("- one\n- two\n");
        }, 'config.file-not-a-map'],
        // An empty file is this state too, which is the point of refusing it: `touch docuccino.yaml`
        // otherwise built a plausible document that had nothing to do with the file.
        'an empty file' => [function (): void {
            BuildSettings::yaml("# nothing yet\n");
        }, 'config.file-not-a-map'],
    ];
}

it('gives every registered command a row', function (): void {
    $rows = [];
    foreach (unreadConfigRefusalRows() as [$class]) {
        $rows[] = $class;
    }

    // The union, against the domain itself: a command registered and listed in neither half of this
    // file would otherwise be checked by nothing at all.
    sort($rows);
    $registered = registeredDocuccinoCommands();
    sort($registered);

    expect($rows)->toBe($registered)
        ->and($rows)->toHaveCount(10);
});

it('declares the refusal exactly where the rows say it does', function (): void {
    // The trait is what makes a command refuse, so the rows and the code are held to each other. A
    // command that grew the trait without a row here — or lost it with the row still claiming it —
    // fails on this line rather than at the next report from an application.
    foreach (unreadConfigRefusalRows() as $name => [$class, $refuses]) {
        expect(in_array(RefusesUnreadConfig::class, class_uses_recursive($class), true))
            ->toBe($refuses, $name.' disagrees with its row');
    }
});

it('refuses, naming the code, before it builds anything', function (string $name): void {
    [$class, , $arguments, $exit] = unreadConfigRefusalRows()[$name];

    arrangeUnmigrated();
    bindStubEngine();
    scriptWatch(1);

    // No `--fail-on` at all: the default is `none`, which is the setting the printed error used to
    // exit 0 under. Nothing here can turn the refusal off.
    test()->artisan($class, $arguments)
        ->expectsOutputToContain('config.not-migrated')
        // The help travels with the refusal, so a reader meets the move wherever they hit it.
        ->expectsOutputToContain('Write the settings you still want into docuccino.yaml')
        ->assertExitCode($exit);
})->with(array_keys(array_filter(unreadConfigRefusalRows(), static fn (array $row): bool => $row[1])));

it('refuses every state a configuration can be unreadable in, not only the one that was reported', function (Closure $arrange, string $code): void {
    $arrange();
    bindStubEngine();

    $out = sys_get_temp_dir().'/docuccino-unread-'.uniqid().'.json';

    try {
        test()->artisan('docuccino:export', ['--format' => 'uir', '--out' => $out])
            ->expectsOutputToContain($code)
            ->assertExitCode(1);

        expect(is_file($out))->toBeFalse();
    } finally {
        @unlink($out);
    }
})->with(unreadConfigStates());

it('refuses even where the run explicitly asks for no gate', function (): void {
    // `--fail-on=none` is a project saying "report, do not fail". It is a say over what a build FOUND,
    // never over whether the configuration was read at all.
    arrangeUnmigrated();
    bindStubEngine();

    test()->artisan('docuccino:export', ['--format' => 'uir', '--fail-on' => 'none'])
        ->expectsOutputToContain('config.not-migrated')
        ->assertExitCode(1);
});

it('writes nothing while it refuses', function (): void {
    // The half a printed error never gave: the artifact assembled from defaults must not reach disk.
    arrangeUnmigrated();
    bindStubEngine();

    $out = sys_get_temp_dir().'/docuccino-refused-'.uniqid().'.json';

    try {
        test()->artisan('docuccino:export', ['--format' => 'uir', '--out' => $out])->assertExitCode(1);

        expect(is_file($out))->toBeFalse();
    } finally {
        @unlink($out);
    }
});

it('runs the commands that owe no refusal', function (string $name): void {
    [$class, , $arguments, $exit] = unreadConfigRefusalRows()[$name];

    arrangeUnmigrated();
    bindStubEngine();

    // The refusal is what must not appear. The exit code is the row's own, because a command that
    // reports this state honestly and then says the application is not ready has not refused anything.
    test()->artisan($class, $arguments)
        ->doesntExpectOutputToContain('config.not-migrated')
        ->assertExitCode($exit);
})->with(array_keys(array_filter(unreadConfigRefusalRows(), static fn (array $row): bool => ! $row[1])));

it('says nothing about the one file state that is not an error', function (): void {
    // A file named nearly right beside no real one is a WARNING, and stays one: there is no
    // `docuccino.yaml`, zero configuration is a supported state, and the document built from defaults
    // is genuinely the product. What that severity should be is a question about the diagnostic rather
    // than about this gate, so the gate reads the severity and does not second-guess the state.
    $directory = sys_get_temp_dir().'/docuccino-misnamed-'.uniqid();
    mkdir($directory, 0777, true);
    file_put_contents($directory.'/docuccino.yml', "documents:\n  default: {}\n");

    app()->instance(BuildConfig::class, new BuildConfig(ConfigFile::read($directory)));
    bindStubEngine();

    $out = $directory.'/out.json';

    try {
        test()->artisan('docuccino:export', ['--format' => 'uir', '--out' => $out])
            ->expectsOutputToContain('config.file-misnamed')
            ->assertExitCode(0);

        expect(is_file($out))->toBeTrue();
    } finally {
        @unlink($out);
        @unlink($directory.'/docuccino.yml');
        @rmdir($directory);
    }
});

it('says nothing about a migrated application', function (): void {
    // The refusal's firing population is the unmigrated shape and nothing else: the suite's own
    // configuration — the shipped `docuccino.yaml` beside the shipped framework config — is silent.
    bindStubEngine();

    test()->artisan('docuccino:validate')
        ->doesntExpectOutputToContain('config.not-migrated')
        ->run();
});
