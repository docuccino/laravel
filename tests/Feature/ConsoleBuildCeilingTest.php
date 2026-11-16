<?php

declare(strict_types=1);

use Docuccino\Core\Pipeline\BuildWorkers;

/*
 * `php artisan docuccino:export` runs under the ceiling it asked for — `engine.memory_limit` or
 * `--memory-limit`, or the default where it asked for none — whatever `APP_ENV` says, and so does every
 * worker it forks. Laravel's console kernel reports no command to `CommandStarting` while the environment is
 * `testing`, so a run that relied on that event stayed at PHP's default there, alone and with no notice to
 * explain dying of it.
 *
 * Each run is a process of its own, started at PHP's stock 128M unless it says otherwise, through the console
 * kernel's `handle()`, which is what the artisan binary calls. Inference is off: the ceiling is settled when
 * the command resolves its engine, before any mode is consulted, so analysing nothing still answers for it.
 */
it('runs a command-line export, and its workers, under the ceiling it asked for', function (string $env, string $yaml, array $flags, string $start, string $ceiling): void {
    $base = temporaryDirectory('console-ceiling');
    mkdir($base.'/bootstrap/cache', 0700, true);
    file_put_contents($base.'/docuccino.yaml', "engine:\n  mode: 'null'\n  workers: 2\n".$yaml);

    try {
        $run = runPhp(<<<'PHP'
            [, $base, $env] = $argv;
            putenv('APP_ENV='.$env);
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $env;

            $app = Orchestra\Testbench\Foundation\Application::create($base, options: ['extra' => [
                'providers' => [Docuccino\Laravel\DocuccinoServiceProvider::class],
                'dont-discover' => ['*'],
            ]]);

            $exit = $app->make(Illuminate\Contracts\Console\Kernel::class)->handle(
                new Symfony\Component\Console\Input\ArgvInput(['artisan', 'docuccino:export', ...array_slice($argv, 3)]),
                new Symfony\Component\Console\Output\BufferedOutput,
            );

            $workers = $app->make(Docuccino\Core\Pipeline\BuildWorkers::class);
            $answered = $workers->answered();
            $worker = $workers->later(static fn (): string => (string) ini_get('memory_limit'))();

            // On a line of its own: the run's output carries whatever PHP printed beside it.
            echo "\nceiling: ".json_encode([
                'exit' => $exit,
                'unitTests' => $app->runningUnitTests(),
                'build' => ini_get('memory_limit'),
                'worker' => $worker,
                'forked' => $workers->answered() > $answered,
            ]);
            PHP, [$base, $env, ...$flags], ini: ['memory_limit' => $start, 'grpc.enable_fork_support' => '1'], timeout: 60.0);

        preg_match('/^ceiling: (.+)$/m', $run['output'], $line);

        expect(json_decode($line[1] ?? 'null', true))->toBe([
            'exit' => 0,
            'unitTests' => $env === 'testing',
            'build' => $ceiling,
            'worker' => $ceiling,
            'forked' => true,
        ], $run['output']);
    } finally {
        removeTemporaryDirectory($base);
    }
})->with([
    'testing' => ['testing'],
    'production' => ['production'],
])->with([
    'engine.memory_limit' => ["  memory_limit: '3G'\n", [], '128M', '3G'],
    '--memory-limit' => ['', ['--memory-limit=3G'], '128M', '3G'],
    // PHP's stock 128M is less than a small application's cold build needs, so a build that names no ceiling
    // gets one that fits it — the way Composer raises itself to 1.5G — rather than dying on its first run.
    'nothing configured' => ['', [], '128M', '1G'],
    // Raise-only, the default as much as a configured value: a process given more keeps it.
    'nothing configured, already higher' => ['', [], '4G', '4G'],
    'nothing configured, unlimited' => ['', [], '-1', '-1'],
    // A ceiling the project wrote is its answer, even one below the default.
    'a configured ceiling below the default' => ["  memory_limit: '512M'\n", [], '128M', '512M'],
])->skip(fn (): bool => ! BuildWorkers::forkable(), 'Forking needs the pcntl and posix extensions.');

it('ends a command-line build that runs out of memory with the notice, after the framework has reported it', function (string $env): void {
    // The framework's error handler registered its shutdown function while the application bootstrapped, so
    // it runs before the notice's, and reports the fatal first — resolving a logger it has not needed yet and
    // rendering the error — on a heap the build has filled. Where that fails, PHP runs no later shutdown
    // function, and the build ended on a second fatal from the logger instead of on the notice.
    $base = temporaryDirectory('console-exhaustion');
    mkdir($base.'/bootstrap/cache', 0700, true);
    file_put_contents($base.'/docuccino.yaml', "engine:\n  mode: 'null'\n  workers: 1\n");

    try {
        $run = runPhp(<<<'PHP'
            [, $base, $env] = $argv;
            putenv('APP_ENV='.$env);
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $env;

            $app = Orchestra\Testbench\Foundation\Application::create($base, options: ['extra' => [
                'providers' => [Docuccino\Laravel\DocuccinoServiceProvider::class],
                'dont-discover' => ['*'],
            ]]);

            $exit = $app->make(Illuminate\Contracts\Console\Kernel::class)->handle(
                new Symfony\Component\Console\Input\ArgvInput(['artisan', 'docuccino:export']),
                new Symfony\Component\Console\Output\BufferedOutput,
            );
            echo "\nexported: {$exit}\n";

            // The build outgrowing its ceiling, a small allocation at a time as an analyser does.
            ini_set('memory_limit', (string) (memory_get_usage(true) + 8 * 1024 * 1024));
            $hog = [];
            while (true) {
                $hog[] = str_repeat('x', 512);
            }
            PHP, [$base, $env], ini: ['memory_limit' => '128M'], timeout: 60.0);

        $explained = strrpos($run['output'], 'Docuccino ran out of memory building your documentation');
        $log = (string) @file_get_contents($base.'/storage/logs/laravel.log');

        expect($run['output'])->toContain('exported: 0')
            ->and($run['exit'])->toBe(255)
            // The framework's report went through: the application's log has the fatal.
            ->and($log)->toContain('Allowed memory size of')
            // And the notice is the last thing the build says, not lost behind a second fatal.
            ->and($explained)->not->toBeFalse($run['output'])
            ->and(trim(substr($run['output'], (int) $explained)))->toEndWith('attributes alone.');
    } finally {
        removeTemporaryDirectory($base);
    }
})->with([
    'testing' => ['testing'],
    'production' => ['production'],
]);

it('ends a command-line build whose worker runs out of memory, even where that worker\'s shutdown would never end', function (): void {
    // A worker inherits every shutdown function the build registered before forking — the framework's error
    // handler among them, which reports the fatal on a full heap — and PHP stops at the first that fails,
    // leaving the worker to module shutdown, where grpc without fork support hangs it. A shutdown function
    // that never returns in a worker stands in for that here; the build must end anyway, on its own fatal
    // and the notice explaining it.
    $base = temporaryDirectory('console-worker-exhaustion');
    mkdir($base.'/bootstrap/cache', 0700, true);
    file_put_contents($base.'/docuccino.yaml', "engine:\n  mode: 'null'\n  workers: 2\n");

    try {
        $run = runPhp(<<<'PHP'
            [, $base] = $argv;
            $app = Orchestra\Testbench\Foundation\Application::create($base, options: ['extra' => [
                'providers' => [Docuccino\Laravel\DocuccinoServiceProvider::class],
                'dont-discover' => ['*'],
            ]]);

            $exit = $app->make(Illuminate\Contracts\Console\Kernel::class)->handle(
                new Symfony\Component\Console\Input\ArgvInput(['artisan', 'docuccino:export']),
                new Symfony\Component\Console\Output\BufferedOutput,
            );
            echo "\nexported: {$exit}\n";

            $build = getmypid();
            register_shutdown_function(static function () use ($build): void {
                while (getmypid() !== $build) {
                    usleep(100000);
                }
            });

            $exhaust = static function (): never {
                ini_set('memory_limit', (string) (memory_get_usage(true) + 8 * 1024 * 1024));
                $hog = [];
                while (true) {
                    $hog[] = str_repeat('x', 512);
                }
            };

            // A worker running out first, then the build where it makes what that worker left.
            $app->make(Docuccino\Core\Pipeline\BuildWorkers::class)->later($exhaust)();
            PHP, [$base], ini: ['memory_limit' => '128M', 'grpc.enable_fork_support' => '0'], timeout: 60.0);

        $explained = strrpos($run['output'], 'Docuccino ran out of memory building your documentation');
        $log = (string) @file_get_contents($base.'/storage/logs/laravel.log');

        expect($run['exit'])->toBe(255, $run['exit'] === null ? 'the build never ended: '.$run['output'] : $run['output'])
            ->and($run['output'])->toContain('exported: 0')
            // One fatal reported, the build's: the worker's own ended it before any reporting could start.
            ->and(substr_count($log, '.ERROR: Allowed memory size of'))->toBe(1, $log)
            ->and($explained)->not->toBeFalse($run['output'])
            ->and(trim(substr($run['output'], (int) $explained)))->toEndWith('attributes alone.');
    } finally {
        removeTemporaryDirectory($base);
    }
})->skip(fn (): bool => ! BuildWorkers::forkable(), 'Forking needs the pcntl and posix extensions.');
