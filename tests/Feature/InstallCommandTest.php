<?php

declare(strict_types=1);

use Docuccino\Laravel\Config\ConfigPublisher;
use Docuccino\Laravel\Engine\EnginePackage;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;

/**
 * `docuccino:install` is the only command in the package that writes anything outside an export path,
 * so most of the coverage here is about what it refuses to do: never replace a config somebody wrote,
 * never need the engine, never fail on an application that has no routes to document yet. The write
 * target is redirected to a temp file — a test that published into the testbench skeleton would leave
 * a `config/docuccino.php` behind for every later test to boot with.
 *
 * Output is read back off the real buffer rather than through `expectsOutputToContain`, whose
 * expectations are one-per-written-line: two substrings of the SAME line satisfy one expectation
 * between them, and this command reports several facts per line on purpose.
 */
function installTarget(): string
{
    $target = sys_get_temp_dir().'/docuccino-install-'.bin2hex(random_bytes(8)).'/config/docuccino.php';

    app()->instance(ConfigPublisher::class, new ConfigPublisher(
        source: dirname(__DIR__, 2).'/config/docuccino.php',
        target: $target,
    ));

    return $target;
}

function removeInstallTarget(string $target): void
{
    @unlink($target);
    @rmdir(dirname($target));
    @rmdir(dirname($target, 2));
}

function shippedConfig(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/config/docuccino.php');
}

it('publishes the config, reports the routes it matched, and says what to do next', function (): void {
    $target = installTarget();
    $this->withoutMockingConsoleOutput();

    expect($this->artisan('docuccino:install', ['--no-export' => true]))->toBe(0);

    expect(Artisan::output())
        ->toContain('Published '.$target)
        ->toContain('(include: api/*)')
        ->toContain('The inference engine is')
        // The one path printed as the project names it rather than as this machine stores it.
        ->toContain('Commit docs/openapi.json')
        ->toContain('http://localhost/docs/api')
        ->and(file_get_contents($target))->toBe(shippedConfig());

    removeInstallTarget($target);
});

/**
 * The count is the resolver's, not a pattern match of our own: the workbench publishes viewer routes
 * under `/docs/api` that no `api/*` document includes, so "documents" is strictly fewer than
 * "publishes" — which is exactly the arithmetic a reader is trying to check.
 */
it('counts only what the document would really document', function (): void {
    $target = installTarget();
    $this->withoutMockingConsoleOutput();

    $this->artisan('docuccino:install', ['--no-export' => true]);

    expect(preg_match('/documents (\d+) of the (\d+) routes/', Artisan::output(), $matches))->toBe(1);

    expect((int) $matches[1])->toBeGreaterThan(0)
        ->and((int) $matches[2])->toBeGreaterThan((int) $matches[1]);

    removeInstallTarget($target);
});

it('never replaces a config somebody already wrote, and says how to ask for that', function (): void {
    $target = installTarget();
    $this->withoutMockingConsoleOutput();
    mkdir(dirname($target), 0755, true);
    file_put_contents($target, "<?php return ['mine' => true];\n");

    expect($this->artisan('docuccino:install', ['--no-export' => true]))->toBe(0);

    expect(Artisan::output())
        ->toContain('is already there, and was left exactly as it is')
        ->toContain('Pass --force to replace it')
        ->not->toContain('Published')
        ->and(file_get_contents($target))->toBe("<?php return ['mine' => true];\n");

    removeInstallTarget($target);
});

it('replaces the config only when --force asks for it', function (): void {
    $target = installTarget();
    $this->withoutMockingConsoleOutput();
    mkdir(dirname($target), 0755, true);
    file_put_contents($target, "<?php return ['mine' => true];\n");

    expect($this->artisan('docuccino:install', ['--no-export' => true, '--force' => true]))->toBe(0);

    expect(Artisan::output())->toContain('Replaced '.$target)
        ->and(file_get_contents($target))->toBe(shippedConfig());

    removeInstallTarget($target);
});

it('runs twice with the same result and the same file', function (): void {
    $target = installTarget();
    $this->withoutMockingConsoleOutput();

    expect($this->artisan('docuccino:install', ['--no-export' => true]))->toBe(0);
    $first = (string) file_get_contents($target);

    expect($this->artisan('docuccino:install', ['--no-export' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('is already there')
        ->and(file_get_contents($target))->toBe($first);

    removeInstallTarget($target);
});

/** A config that cannot be written stops the run: every later step would report on a file that isn't there. */
it('fails when the config cannot be written', function (): void {
    $this->withoutMockingConsoleOutput();

    app()->instance(ConfigPublisher::class, new ConfigPublisher(
        source: dirname(__DIR__, 2).'/config/docuccino.php',
        target: '/dev/null/docuccino/config/docuccino.php',
    ));

    expect($this->artisan('docuccino:install', ['--no-export' => true]))->toBe(1)
        ->and(Artisan::output())
        ->toContain('Could not write')
        ->not->toContain('Engine');
});

/**
 * The whole point of the routes step: an application whose API does not live under `api/*` is told
 * where it does live, in the pattern it would paste.
 */
it('names the prefixes an application really uses when the pattern matches nothing', function (): void {
    $target = installTarget();
    $this->withoutMockingConsoleOutput();
    config()->set('docuccino.documents.default.routes.include', ['nope/*']);

    expect($this->artisan('docuccino:install', ['--no-export' => true]))->toBe(0);

    expect(Artisan::output())
        ->toContain('"default" documents 0 of the ')
        ->toContain('matched nothing. Your routes sit under:')
        ->toContain('api/*')
        ->toContain('Set documents.default.routes.include in ')
        ->toContain("e.g. ['api/*']");

    removeInstallTarget($target);
});

it('says something useful on an application with no routes at all', function (): void {
    $target = installTarget();
    $this->withoutMockingConsoleOutput();
    app('router')->setRoutes(new RouteCollection);

    expect($this->artisan('docuccino:install', ['--no-export' => true]))->toBe(0);

    expect(Artisan::output())
        ->toContain('publishes no routes Docuccino could document')
        ->toContain('routes.include_vendor')
        ->not->toContain('matched nothing');

    removeInstallTarget($target);
});

it('reports the engine the same way an export warns about it', function (bool $installed, ?string $mode, string $expected): void {
    $target = installTarget();
    $this->withoutMockingConsoleOutput();

    app()->instance(EnginePackage::class, new EnginePackage(static fn (string $class): bool => $installed));
    if ($mode !== null) {
        config()->set('docuccino.engine.mode', $mode);
    }

    expect($this->artisan('docuccino:install', ['--no-export' => true]))->toBe(0)
        ->and(Artisan::output())->toContain($expected);

    removeInstallTarget($target);
})->with([
    'installed' => [true, null, 'The inference engine is installed.'],
    'absent' => [false, null, 'The inference engine is not installed'],
    // An explicit opt-out is not a missing package, whichever way the probe answers.
    'absent, mode null' => [false, 'null', 'Inference is switched off (engine.mode = null).'],
    'installed, mode null' => [true, 'null', 'Inference is switched off (engine.mode = null).'],
]);

it('names the one command that fixes an absent engine', function (): void {
    $target = installTarget();
    $this->withoutMockingConsoleOutput();
    app()->instance(EnginePackage::class, new EnginePackage(static fn (string $class): bool => false));

    expect($this->artisan('docuccino:install', ['--no-export' => true]))->toBe(0);

    expect(Artisan::output())
        ->toContain('composer require --dev docuccino/inference-phpstan')
        ->toContain('DOCUCCINO_ENGINE=null');

    removeInstallTarget($target);
});

/**
 * `--no-interaction` takes the prompt's default, and the default is yes: a scripted setup that asked
 * to be set up gets a document rather than a to-do.
 */
it('exports without interaction, and reports where the artifact went', function (): void {
    bindStubEngine();
    $target = installTarget();
    $this->withoutMockingConsoleOutput();
    $artifact = sys_get_temp_dir().'/docuccino-install-export-'.bin2hex(random_bytes(8)).'.json';
    config()->set('docuccino.documents.default.export.path', $artifact);

    expect($this->artisan('docuccino:install', ['--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Wrote '.$artifact)
        ->and(file_exists($artifact))->toBeTrue();

    @unlink($artifact);
    removeInstallTarget($target);
});

it('leaves the export alone when the prompt is declined', function (): void {
    $target = installTarget();

    $this->artisan('docuccino:install')
        ->expectsConfirmation('Export one now?', 'no')
        ->expectsOutputToContain('Skipped.')
        ->doesntExpectOutputToContain('Wrote ')
        ->assertSuccessful();

    removeInstallTarget($target);
});

/** The setup succeeded and the export did not: the exit code has to say so, or CI reads a green run. */
it('fails when the first export fails', function (): void {
    bindStubEngine();
    $target = installTarget();
    $this->withoutMockingConsoleOutput();
    config()->set('docuccino.documents.default.export.path', '/dev/null/nope/openapi.json');

    expect($this->artisan('docuccino:install', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('Could not create');

    removeInstallTarget($target);
});

it('refuses to run while Docuccino is disabled', function (): void {
    $target = installTarget();
    $this->withoutMockingConsoleOutput();
    config()->set('docuccino.enabled', false);

    expect($this->artisan('docuccino:install', ['--no-export' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('Docuccino is disabled')
        ->and(file_exists($target))->toBeFalse();

    removeInstallTarget($target);
});
