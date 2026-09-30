<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\BuildWorkers;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Commands\MemoryLimitOption;
use Docuccino\Laravel\Engine\EnginePackage;
use Docuccino\Laravel\Engine\LazyTypeEngine;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Api\ReportController;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Console\Kernel as FoundationConsoleKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

/*
 * A cold build handed to forked workers is the serial build. Each worker leaves whole fragments where the
 * build restores them, and restoring is the warm path, which already equals the cold one — so these compare
 * the two builds byte for byte, diagnostics included, through both places the fragments can go: the
 * configured store, and a directory of the build's own where that store keeps nothing.
 */

beforeEach(function (): void {
    // Made before anything can skip, because the sweep below runs after a skipped test as well.
    $this->scratch = temporaryDirectory('parallel');

    if (! BuildWorkers::forkable()) {
        $this->markTestSkipped('Forking needs the pcntl and posix extensions.');
    }
});

afterEach(function (): void {
    removeTemporaryDirectory($this->scratch ?? null);
});

/** The document's bytes and every diagnostic, which is what "the same build" has to mean. */
function buildOutcome(GenerationResult $result): array
{
    return [
        (new UirEmitter)->emit($result->document),
        array_map(static fn (Diagnostic $diagnostic): array => $diagnostic->toArray(), $result->diagnostics),
    ];
}

/**
 * A build with `$workers` at most, counting the forks: beforeFork runs once for the operations a build hands
 * out, and once more for the worker its lints run in.
 */
function buildWith(int $workers, TypeEngine $engine, int &$forked = 0): GenerationResult
{
    app()->instance(TypeEngine::class, $engine);
    app()->instance(BuildWorkers::class, new BuildWorkers(
        static fn (): int => $workers,
        beforeFork: static function () use (&$forked): void {
            $forked++;
        },
    ));

    return generateDocument();
}

/**
 * What `docuccino:export` writes and prints, run as the product runs it: through the container's own
 * workers, in a process marked as started to run the command.
 *
 * @return array{string, string}
 */
function exportedAsConsoleBuild(string $out): array
{
    MemoryLimitOption::capture(new CommandStarting('docuccino:export', new ArgvInput(['artisan', 'docuccino:export']), new NullOutput));

    // A command of its own each time, as each process gets one: a command links a code's docs once per run,
    // and a run's workers are its own, limit and all.
    $kernel = app(ConsoleKernel::class);
    assert($kernel instanceof FoundationConsoleKernel);
    $kernel->setArtisan(null);
    app()->forgetScopedInstances();

    $exit = Artisan::call('docuccino:export', ['--format' => 'openapi-3.2', '--out' => $out]);

    expect($exit)->toBe(0);

    return [(string) file_get_contents($out), Artisan::output()];
}

it('builds with three workers the document one process builds, with the cache off', function (): void {
    $serial = buildOutcome(buildWith(1, WorkbenchEngine::make()));

    $engine = countingEngine(WorkbenchEngine::make());
    $forked = 0;
    $parallel = buildOutcome(buildWith(3, $engine, $forked));

    expect($forked)->toBe(2)
        // The workers answered every operation: nothing was left for this process to ask the engine about.
        ->and($engine->asked)->toBe(0)
        ->and($parallel)->toBe($serial);
});

it('builds with three workers the document one process builds, into the configured store', function (): void {
    $serial = buildOutcome(buildWith(1, WorkbenchEngine::make()));

    setBuild('cache.enabled', true);
    setBuild('cache.path', $this->scratch.'/fragments');

    $engine = countingEngine(WorkbenchEngine::make());
    $forked = 0;
    $parallel = buildOutcome(buildWith(3, $engine, $forked));

    // …and what the workers stored is the store's: the next build is warm, so its lints' worker is the only
    // one it starts.
    $again = 0;
    $warm = buildOutcome(buildWith(3, countingEngine(WorkbenchEngine::make()), $again));

    expect($forked)->toBe(2)
        // Every operation came back out of the store the workers wrote: had they written anywhere else, this
        // process would have built them all again and the document would still have come out the same.
        ->and($engine->asked)->toBe(0)
        ->and($parallel)->toBe($serial)
        ->and(glob($this->scratch.'/fragments/*.json'))->not->toBeEmpty()
        ->and($again)->toBe(1)
        ->and($warm)->toBe($serial);
});

it('builds the same document when a worker dies with work half done', function (): void {
    $serial = buildOutcome(buildWith(1, WorkbenchEngine::make()));

    $engine = countingEngine(WorkbenchEngine::make(), deaths: $this->scratch);
    $parallel = buildOutcome(buildWith(3, $engine));

    // One worker really died, and what it left unbuilt this process built itself.
    expect(is_file($this->scratch.'/died'))->toBeTrue()
        ->and($engine->asked)->toBeGreaterThan(0)
        ->and($parallel)->toBe($serial);
});

it('leaves a fallback route out of the work it hands out, as the build it hands it to does', function (): void {
    // A catch-all is omitted and reported rather than built, so there is nothing for a worker to make of it.
    app('router')->prefix('api')->group(static function (Router $router): void {
        $router->fallback([ReportController::class, 'index']);
    });

    $serial = buildWith(1, WorkbenchEngine::make());
    $forked = 0;
    $parallel = buildWith(3, WorkbenchEngine::make(), $forked);

    expect($forked)->toBe(2)
        ->and(buildOutcome($parallel))->toBe(buildOutcome($serial))
        ->and(diagnosticsCoded($parallel->diagnostics, 'route.fallback-omitted'))->toHaveCount(1);
});

it('leaves nothing of its own behind in the temporary directory', function (): void {
    // This process's own directories: a suite running in parallel has other builds making theirs meanwhile.
    $leftovers = static fn (): array => [
        ...(glob(sys_get_temp_dir().'/docuccino-fragments-'.getmypid().'-*') ?: []),
        ...(glob(sys_get_temp_dir().'/docuccino-claims-'.getmypid().'-*') ?: []),
    ];
    $before = $leftovers();

    $forked = 0;
    buildWith(3, WorkbenchEngine::make(), $forked);

    // Workers ran, so both directories were made: a build that forked nothing would pass the last line too.
    expect($forked)->toBe(2)
        ->and($leftovers())->toBe($before);
});

it('builds its operations in one process when too few are cold to share', function (): void {
    setBuild('cache.enabled', true);
    setBuild('cache.path', $this->scratch.'/fragments');
    // The same engine class both times: which engine answers is part of every fragment's key.
    $serial = buildOutcome(buildWith(1, countingEngine(WorkbenchEngine::make())));

    // Three workers' worth were cold the first time; three operations are cold again now, where one worker
    // has to be worth eight of them.
    $stored = glob($this->scratch.'/fragments/*.json') ?: [];
    expect(count($stored))->toBeGreaterThan(3 * BuildWorkers::MIN_OPERATIONS);
    array_map('unlink', array_slice($stored, 0, 3));

    $engine = countingEngine(WorkbenchEngine::make());
    $forked = 0;
    $again = buildOutcome(buildWith(3, $engine, $forked));

    // The lints' worker, and none for the operations.
    expect($forked)->toBe(1)
        ->and($engine->asked)->toBeGreaterThan(0)
        ->and($again)->toBe($serial);
});

it('builds its operations in one process when the engine it would have shared will not boot', function (): void {
    // Booted ahead of the fork so every worker inherits one analyser; one that failed to boot answers nothing
    // a fragment may be filed under, so no worker could leave the build anything to read back.
    $failed = static fn (): TypeEngine => new LazyTypeEngine(
        static fn (): TypeEngine => new NullTypeEngine('the app would not boot'),
        EnginePackage::BUILDER,
    );
    $serial = buildOutcome(buildWith(1, $failed()));

    $forked = 0;
    $again = buildOutcome(buildWith(3, $failed(), $forked));

    // The lints' worker, and none for the operations.
    expect($forked)->toBe(1)
        ->and($again)->toBe($serial);
});

it('builds the serial artifact as a console build, through the product\'s own wiring', function (): void {
    // Everything a real `php artisan docuccino:export` does around a fork, rather than a hand-built set of
    // workers: the configured count, the connections closed first, and each worker quietened.
    $out = $this->scratch.'/openapi.json';
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
    setBuild('engine.workers', 1);
    $serial = exportedAsConsoleBuild($out);

    setBuild('engine.workers', 3);
    $engine = countingEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);
    DB::connection()->getPdo();

    $parallel = exportedAsConsoleBuild($out);

    expect($engine->asked)->toBe(0)
        ->and(DB::connection()->getRawPdo())->toBeNull()
        ->and($parallel)->toBe($serial);
});
