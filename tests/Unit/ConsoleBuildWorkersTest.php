<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\BuildWorkers;
use Docuccino\Laravel\Commands\MemoryLimitOption;
use Docuccino\Laravel\Engine\ConsoleBuild;
use Docuccino\Laravel\Engine\ConsoleBuildWorkers;
use Docuccino\Laravel\Engine\TypeEngineFactory;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerReflector;
use Docuccino\Laravel\Integrations\InferredHandler\RenderCallback;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Redis\Connector;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Console\Kernel as FoundationConsoleKernel;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\ErrorHandler\Error\FatalError;

/*
 * A Laravel build forks only in a process that is the build's own: one started to run a Docuccino command.
 * A web request building the viewer's document, and a command called in-process — Artisan::call() from a
 * job, a request or another command — share their process with other work. In a console build it takes as
 * many workers as `engine.workers` says, or as the machine allows, and settles the process around the fork.
 */

/** Mark this process as started to run `docuccino:export`, the way `php artisan docuccino:export` starts it. */
function markConsoleBuild(): void
{
    MemoryLimitOption::capture(new CommandStarting('docuccino:export', new ArgvInput(['artisan', 'docuccino:export']), new NullOutput));
}

/**
 * An exception handler that writes down what it is asked to report or render.
 *
 * @param  list<string>  $seen
 */
function recordingExceptionHandler(array &$seen): ExceptionHandler
{
    return new class($seen) implements ExceptionHandler
    {
        /** @param  list<string>  $seen */
        public function __construct(private array &$seen) {}

        public function report(Throwable $e): void
        {
            $this->seen[] = 'reported '.$e->getMessage();
        }

        public function shouldReport(Throwable $e): bool
        {
            return true;
        }

        public function render($request, Throwable $e): never
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e): void
        {
            $this->seen[] = 'rendered '.$e->getMessage();
        }
    };
}

it('never forks outside a console build, whatever is configured', function (): void {
    setBuild('engine.workers', 4);

    expect(ConsoleBuildWorkers::for(app())->for(1000))->toBe(1)
        ->and(ConsoleBuildWorkers::for(app())->mayFork())->toBeFalse();
})->skip(fn (): bool => ! BuildWorkers::forkable(), 'Forking needs the pcntl and posix extensions.');

it('takes the configured count on the console', function (): void {
    setBuild('engine.workers', 3);
    markConsoleBuild();

    expect(ConsoleBuildWorkers::for(app())->for(1000))->toBe(3);
})->skip(fn (): bool => ! BuildWorkers::forkable(), 'Forking needs the pcntl and posix extensions.');

it('builds in one process on the console for a count it cannot read', function (mixed $configured): void {
    // The same fallback the refusal of such a value names, so the line an author is sent to agrees with
    // what the build did: an off switch that fails closed.
    setBuild('engine.workers', $configured);
    markConsoleBuild();

    expect(ConsoleBuildWorkers::for(app())->mayFork())->toBeFalse();
})->with([
    'zero' => [0],
    'off' => [false],
    'a quoted count' => ['4'],
])->skip(fn (): bool => ! BuildWorkers::forkable(), 'Forking needs the pcntl and posix extensions.');

it('is no console build when a Docuccino command is called in-process', function (InputInterface $input): void {
    // Artisan::call() from a queued job, an HTTP request or an Octane worker reports the command starting
    // too, handed an ArrayInput or a StringInput. That process serves other work — a transaction open
    // around the call, sockets it shares, a memory ceiling it runs every other job under — so a build
    // inside it neither forks nor tunes it.
    setBuild('engine.workers', 4);

    MemoryLimitOption::capture(new CommandStarting('docuccino:export', $input, new NullOutput));

    expect(ConsoleBuild::active())->toBeFalse()
        ->and(app(TypeEngineFactory::class)->mayTuneProcess())->toBeFalse()
        ->and(ConsoleBuildWorkers::for(app())->mayFork())->toBeFalse();
})->with([
    'Artisan::call() with parameters' => [new ArrayInput(['command' => 'docuccino:export', '--format' => 'openapi-3.2'])],
    'Artisan::call() with a command line' => [new StringInput('docuccino:export --format=openapi-3.2')],
]);

it('leaves a job\'s open transaction alone when the job calls the export in-process', function (): void {
    // Outside a test suite the framework reports every command it starts to CommandStarting, Artisan::call()
    // included — which is what a queued job or a controller calling the export gets.
    $kernel = app(ConsoleKernel::class);
    assert($kernel instanceof FoundationConsoleKernel);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->setArtisan(null);

    setBuild('engine.workers', 3);
    $engine = countingEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);
    $out = temporaryDirectory('in-process-export');

    DB::beginTransaction();

    try {
        Artisan::call('docuccino:export', ['--format' => 'openapi-3.2', '--out' => $out.'/openapi.json']);

        // Built here, in the job's process, and the job's transaction is the one it opened.
        expect(DB::transactionLevel())->toBe(1)
            ->and($engine->asked)->toBeGreaterThan(0)
            ->and(is_file($out.'/openapi.json'))->toBeTrue();
    } finally {
        DB::rollBack();
        removeTemporaryDirectory($out);
    }
})->skip(fn (): bool => ! BuildWorkers::forkable(), 'Forking needs the pcntl and posix extensions.');

it('stays a console build when it calls a Docuccino command of its own', function (): void {
    // `docuccino:install` runs the export in-process, and the process is still the one it was started as.
    markConsoleBuild();
    MemoryLimitOption::capture(new CommandStarting('docuccino:export', new ArrayInput(['command' => 'docuccino:export']), new NullOutput));

    expect(ConsoleBuild::active())->toBeTrue();
});

it('closes the database connections this process holds before it forks', function (): void {
    markConsoleBuild();
    DB::connection()->getPdo();

    expect(DB::connection()->getRawPdo())->not->toBeNull();

    ConsoleBuildWorkers::for(app())->run(1, [['job']], static function (string $job): void {});

    expect(DB::connection()->getRawPdo())->toBeNull();
})->skip(fn (): bool => ! BuildWorkers::forkable(), 'Forking needs the pcntl and posix extensions.');

it('closes the Redis connections this process holds before it forks', function (): void {
    // In this process: closing happens on this side of the fork. A worker that queries opens its own.
    $closed = 0;
    $connection = new class($closed) extends Connection
    {
        public function __construct(private int &$closed) {}

        public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void {}

        public function disconnect(): void
        {
            $this->closed++;
        }
    };

    $redis = new RedisManager(app(), 'recorded', ['default' => ['host' => '127.0.0.1']]);
    // Not static: the manager binds a driver's creator to itself.
    $redis->extend('recorded', fn (): Connector => new class($connection) implements Connector
    {
        public function __construct(private readonly Connection $connection) {}

        public function connect(array $config, array $options): Connection
        {
            return $this->connection;
        }

        public function connectToCluster(array $config, array $clusterOptions, array $options): Connection
        {
            return $this->connection;
        }
    });
    $redis->connection();
    app()->instance(RedisManager::class, $redis);

    markConsoleBuild();
    ConsoleBuildWorkers::for(app())->run(1, [['job']], static function (string $job): void {});

    expect($closed)->toBe(1)
        ->and($redis->connections())->toBe([]);
})->skip(fn (): bool => ! BuildWorkers::forkable(), 'Forking needs the pcntl and posix extensions.');

it('keeps a worker\'s failures out of the application\'s error reporting', function (): void {
    // A worker that dies of a fatal error runs the shutdown work it inherited, and the framework's hands
    // the error to the application's exception handler — logs, error trackers, the console the worker
    // shares — for a failure the build then recovers from. That path is Laravel's own, driven here.
    $seen = [];
    app()->instance(ExceptionHandler::class, recordingExceptionHandler($seen));
    $fatal = new FatalError('Allowed memory size of 134217728 bytes exhausted', 0, ['type' => E_ERROR, 'message' => 'Allowed memory size of 134217728 bytes exhausted', 'file' => __FILE__, 'line' => __LINE__]);

    (new HandleExceptions)->handleException($fatal);
    $before = $seen;

    ConsoleBuildWorkers::quietWorker(app());
    (new HandleExceptions)->handleException($fatal);

    // The first is what a worker reported before; the second, what one reports now. A request is not a thing
    // a worker serves, so rendering one hands the failure back rather than answering it.
    expect($before)->toBe(['reported Allowed memory size of 134217728 bytes exhausted', 'rendered Allowed memory size of 134217728 bytes exhausted'])
        ->and($seen)->toBe($before)
        ->and(app(ExceptionHandler::class)->shouldReport($fatal))->toBeFalse()
        ->and(fn () => app(ExceptionHandler::class)->render(request(), $fatal))->toThrow(FatalError::class);
});

it('quietens a worker without changing what a build reads off the exception handler', function (): void {
    // The inferred-handler tier documents error responses from the handler's render callbacks, and anything
    // that asked for the handler inside a worker would ask after it was quietened.
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable(static fn (RuntimeException $e) => response()->json(['error' => 'boom'], 400));
    $callbacks = static fn (): array => array_map(
        static fn (RenderCallback $callback): string => $callback->exceptionType.' '.$callback->parameterName,
        (new HandlerReflector(app(ExceptionHandler::class)))->renderCallbacks(),
    );
    $before = $callbacks();

    ConsoleBuildWorkers::quietWorker(app());

    // …and what is registered through the quiet handler reaches the application's, as it would unquietened.
    /** @var object $quiet */
    $quiet = app(ExceptionHandler::class);
    $quiet->renderable(static fn (LogicException $e) => response()->json(['error' => 'logic'], 409));

    expect($before)->toBe(['RuntimeException e'])
        ->and($callbacks())->toBe(['RuntimeException e', 'LogicException e']);
});

it('quietens every worker it forks', function (): void {
    $out = temporaryDirectory('quiet-worker');
    $seen = [];
    app()->instance(ExceptionHandler::class, recordingExceptionHandler($seen));
    markConsoleBuild();

    try {
        ConsoleBuildWorkers::for(app())->run(1, [['job']], static function (string $job) use ($out): void {
            file_put_contents($out.'/handler', app(ExceptionHandler::class)::class);
        });

        expect(file_get_contents($out.'/handler'))->not->toBe(app(ExceptionHandler::class)::class);
    } finally {
        removeTemporaryDirectory($out);
    }
})->skip(fn (): bool => ! BuildWorkers::forkable(), 'Forking needs the pcntl and posix extensions.');
