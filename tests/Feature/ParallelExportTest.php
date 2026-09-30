<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\Assembler;
use Docuccino\Core\Pipeline\BuildWorkers;
use Docuccino\Laravel\Commands\ExportCommand;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * The lints, the schema check and each target's emit read the document and nothing else, so where the build
 * may fork they run side by side with the rest of it. All that may change is the time: each test here puts
 * work done by workers beside the same work done in one process, and compares everything the two produced. A
 * task no worker answered is made here, and makes the same bytes, so each also holds the workers to having
 * answered: nothing else tells a worker that works from one that never does.
 *
 * The fragment store is warm for every run that counts workers, so the operations — which a cold build
 * hands to workers of its own — start none of them.
 */

beforeEach(function (): void {
    // Made before anything can skip, because the sweep below runs after a skipped test as well.
    $this->dir = temporaryDirectory('parallel-export');

    if (! BuildWorkers::forkable()) {
        $this->markTestSkipped('Forking needs the pcntl and posix extensions.');
    }

    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    mkdir($this->dir.'/artifacts');

    setBuild('cache.enabled', true);
    setBuild('cache.path', $this->dir.'/fragments');
    setBuild('documents.default.export', ['targets' => [
        ['format' => 'openapi-3.2', 'path' => $this->dir.'/artifacts/openapi.json'],
        ['format' => 'openapi-3.1', 'path' => $this->dir.'/artifacts/openapi-3.1.yaml'],
        ['format' => 'full', 'path' => $this->dir.'/artifacts/api.uir.json'],
        ['format' => 'postman', 'path' => $this->dir.'/artifacts/collection.json'],
    ]]);
});

afterEach(function (): void {
    removeTemporaryDirectory($this->dir ?? null);
});

/** Workers for at most `$limit`, counting in `$started` every one that is forked. */
function countedWorkers(int $limit, int &$started): BuildWorkers
{
    return new BuildWorkers(static fn (): int => $limit, beforeFork: static function () use (&$started): void {
        $started++;
    });
}

/**
 * One export with at most `$limit` workers: what it returned, printed and wrote, how many workers it started
 * and how many of them answered. Each is a command of its own, since a command links a diagnostic's docs only
 * the first time it prints that code, and each starts from an empty artifact directory, so one run's file
 * cannot pass for another's.
 *
 * @return array{int, string, array<string, string>, int, int}
 */
function exportWith(int $limit, string $artifacts): array
{
    $started = 0;
    $workers = countedWorkers($limit, $started);
    app()->instance(BuildWorkers::class, $workers);

    array_map('unlink', glob($artifacts.'/*') ?: []);
    $command = app(ExportCommand::class);
    $command->setLaravel(app());
    $output = new BufferedOutput;
    $exit = $command->run(new ArrayInput([]), $output);

    $written = [];
    foreach (glob($artifacts.'/*') ?: [] as $file) {
        $written[basename($file)] = (string) file_get_contents($file);
    }

    return [$exit, $output->fetch(), $written, $started, $workers->answered()];
}

it('writes and reports every target exactly as one process does, with the lints and the schema check beside them', function (): void {
    [$exit, $output, $written] = exportWith(1, $this->dir.'/artifacts');
    [$parallelExit, $parallelOutput, $parallelWritten, $started, $answered] = exportWith(8, $this->dir.'/artifacts');

    // Both runs share every line of the command, so what they agree on is also held to what it must be: each
    // target reported in the order it is configured, and what an emit said about its artifact — the
    // collection's empty `baseUrl`, since the workbench declares no server — reported with it.
    preg_match_all('/^Wrote \S+\/(\S+) \(/m', $output, $reported);

    // The lints, the schema check and every target's emit, each in a worker of its own that answered for it.
    expect($started)->toBe(6)
        ->and($answered)->toBe($started)
        ->and(array_keys($written))->toBe(['api.uir.json', 'collection.json', 'openapi-3.1.yaml', 'openapi.json'])
        ->and($reported[1])->toBe(['openapi.json', 'openapi-3.1.yaml', 'api.uir.json', 'collection.json'])
        ->and($output)->toContain('postman.no-server')
        ->and($parallelWritten)->toBe($written)
        ->and($parallelOutput)->toBe($output)
        ->and($parallelExit)->toBe($exit);
});

it('shares one limit between everything beside the build, and does what is past it here', function (): void {
    [, $output, $written] = exportWith(1, $this->dir.'/artifacts');

    // Three at once: this process, the lints and the schema check, so every emit is made here.
    [, $limitedOutput, $limitedWritten, $started, $answered] = exportWith(3, $this->dir.'/artifacts');

    expect($started)->toBe(2)
        ->and($answered)->toBe(2)
        ->and($limitedWritten)->toBe($written)
        ->and($limitedOutput)->toBe($output);
});

it('reports a document its schema refuses the same when the check runs beside the caller', function (): void {
    // The one malformed shape SecurityAuditTest shows only the schema check can locate: the scheme where
    // its scopes go.
    bindStubEngine();
    $raw = documentSettings('default');
    $raw['security']['schemes'] = ['bearer' => ['type' => 'http', 'scheme' => 'bearer']];
    $raw['security']['default'] = [['bearer']];
    $config = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');
    $generate = static fn (BuildWorkers $workers, ?Closure $meanwhile): array => [
        app()->makeWith(DocumentGenerator::class, ['workers' => $workers])->generate($config, app(TypeEngine::class), meanwhile: $meanwhile),
    ];

    $unused = 0;
    [$serial] = $generate(countedWorkers(1, $unused), null);

    $started = 0;
    $handed = [];
    $workers = countedWorkers(4, $started);
    [$beside] = $generate($workers, static function (UirDocument $document) use (&$handed): void {
        $handed[] = (new UirEmitter)->emit($document);
    });

    $outcome = static fn ($result): array => [
        (new UirEmitter)->emit($result->document),
        array_map(static fn (Diagnostic $diagnostic): array => $diagnostic->toArray(), $result->diagnostics),
    ];

    expect(array_filter($serial->diagnostics, static fn (Diagnostic $d): bool => $d->code === 'document.schema-invalid'))->not->toBeEmpty()
        ->and($started)->toBe(1)
        ->and($workers->answered())->toBe(1)
        ->and($outcome($beside))->toBe($outcome($serial))
        // Handed once, and handed the document the build returns.
        ->and($handed)->toBe([(new UirEmitter)->emit($serial->document)]);
});

it('makes each artifact after writing the one before it, and holds one at a time, in one process as with workers', function (int $limit): void {
    // Artifacts the size of a large application's: the description alone runs to megabytes, in each of them.
    setBuild('documents.default.info.description', str_repeat('What a reader of this interface needs to know. ', 90_000));
    setBuild('documents.default.export', ['targets' => array_map(
        fn (string $format): array => ['format' => $format, 'path' => $this->dir.'/artifacts/'.$format.'.json'],
        ['openapi-3.2', 'openapi-3.1', 'openapi-3.0', 'full'],
    )]);
    exportWith($limit, $this->dir.'/artifacts');

    // As each file is reported written: what this process holds, where an artifact held past its write would
    // add itself to every later figure; and the most it held since the file before, which an artifact emitted
    // or read back in between raises by that artifact.
    $held = [];
    $made = [];
    $output = new class($held, $made) extends BufferedOutput
    {
        private int $last;

        /**
         * @param  list<int>  $held
         * @param  list<int>  $made
         */
        public function __construct(private array &$held, private array &$made)
        {
            parent::__construct();
            $this->last = memory_get_usage();
        }

        protected function doWrite(string $message, bool $newline): void
        {
            if (str_starts_with($message, 'Wrote ')) {
                $this->held[] = memory_get_usage();
                $this->made[] = memory_get_peak_usage() - $this->last;
                memory_reset_peak_usage();
                $this->last = memory_get_usage();
            }

            parent::doWrite($message, $newline);
        }
    };
    $started = 0;
    app()->instance(BuildWorkers::class, countedWorkers($limit, $started));
    $command = app(ExportCommand::class);
    $command->setLaravel(app());

    $exit = $command->run(new ArrayInput([]), $output);
    $artifact = (int) filesize($this->dir.'/artifacts/full.json');

    expect($exit)->toBe(0)
        ->and($artifact)->toBeGreaterThan(4_000_000)
        ->and($held)->toHaveCount(4)
        ->and(max($held) - $held[0])->toBeLessThan($artifact)
        // In one process an emit that ran before the first write would leave nothing to make between writes.
        ->and(min(array_slice($made, 1)))->toBeGreaterThan(intdiv($artifact, 2));
})->with(['in one process' => 1, 'with workers' => 8]);

it('leaves no worker behind when writing a target throws', function (): void {
    exportWith(1, $this->dir.'/artifacts');

    // A console whose reader has gone, as a closed pipe leaves it: the first report of a written file throws,
    // with the other targets' emits started and not yet asked for.
    $output = new class extends BufferedOutput
    {
        protected function doWrite(string $message, bool $newline): void
        {
            if (str_starts_with($message, 'Wrote ')) {
                throw new RuntimeException('Unable to write output.');
            }

            parent::doWrite($message, $newline);
        }
    };
    $started = 0;
    app()->instance(BuildWorkers::class, countedWorkers(8, $started));
    $command = app(ExportCommand::class);
    $command->setLaravel(app());

    expect(static fn () => $command->run(new ArrayInput([]), $output))->toThrow(RuntimeException::class, 'Unable to write output.')
        ->and($started)->toBe(6)
        // No child of this process is left, finished or not: each worker was ended and reaped as its answer
        // was let go, the lints' among them.
        ->and(pcntl_waitpid(-1, $status, WNOHANG))->toBe(-1);
});

it('hands every part of a run that can start a worker the one set of workers the run has', function (): void {
    // The lints are started by the assembler, the schema check by the generator and the emits by the command,
    // and they share one limit only by sharing one set of workers: each set would keep to the limit alone.
    $workers = app(BuildWorkers::class);
    $generator = (new ReflectionProperty(DocumentBuilder::class, 'generator'))->getValue(app(DocumentBuilder::class));
    $assembler = (new ReflectionProperty(DocumentGenerator::class, 'assembler'))->getValue($generator);

    expect(app(BuildWorkers::class))->toBe($workers)
        ->and((new ReflectionProperty(DocumentGenerator::class, 'workers'))->getValue($generator))->toBe($workers)
        ->and((new ReflectionProperty(Assembler::class, 'workers'))->getValue($assembler))->toBe($workers);
});
