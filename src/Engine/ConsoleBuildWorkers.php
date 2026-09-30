<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

use Docuccino\Core\Pipeline\BuildWorkers;
use Docuccino\Core\Pipeline\WorkerCount;
use Docuccino\Laravel\Config\BuildConfig;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\DatabaseManager;
use Illuminate\Redis\RedisManager;
use Throwable;

/**
 * The workers a Laravel build may fork ({@see BuildWorkers}): as many as `engine.workers` says, or as the
 * machine allows where it says nothing — and none outside a {@see ConsoleBuild}, a process that is the
 * build's own. The hooks are the framework's half of forking.
 *
 * @internal
 */
final class ConsoleBuildWorkers
{
    public static function for(Container $app): BuildWorkers
    {
        return new BuildWorkers(
            limit: static fn (): int => ConsoleBuild::active()
                ? WorkerCount::of(
                    $app->make(BuildConfig::class)->engine()['workers'] ?? null,
                    ceiling: MemoryLimit::bytes((string) ini_get('memory_limit')),
                )
                : 1,
            beforeFork: static fn () => self::closeConnections($app),
            inWorker: static fn () => self::quietWorker($app),
        );
    }

    /**
     * Keep a worker's failures out of the application's error reporting. One that dies of a fatal error runs
     * the shutdown work it inherited, and the framework's hands the error to the exception handler — logs,
     * error trackers, the console the worker shares — for a failure the build then recovers from.
     *
     * The application's handler is wrapped rather than replaced: a build reads it (its render callbacks
     * document error responses), and a reader walks a decorator to the handler it holds, as it does
     * Collision's, so the worker reads what the build does and only reporting and rendering go quiet.
     */
    public static function quietWorker(Container $app): void
    {
        $app->instance(ExceptionHandler::class, new class($app->make(ExceptionHandler::class)) implements ExceptionHandler
        {
            public function __construct(private readonly ExceptionHandler $handler) {}

            public function report(Throwable $e): void {}

            public function shouldReport(Throwable $e): bool
            {
                return false;
            }

            public function render($request, Throwable $e): never
            {
                throw $e;
            }

            public function renderForConsole($output, Throwable $e): void {}

            /** @param  array<array-key, mixed>  $arguments */
            public function __call(string $method, array $arguments): mixed
            {
                return $this->handler->{$method}(...$arguments);
            }
        });
    }

    /**
     * Close every database and Redis connection this process holds. A worker inherits the socket along with
     * everything else, and two processes speaking on one connection corrupt both; a worker that queries
     * opens a connection of its own, and so does the build when it next needs one.
     */
    private static function closeConnections(Container $app): void
    {
        // Only a manager this build already made can hold a connection, so neither is made here.
        if ($app->resolved(DatabaseManager::class)) {
            $db = $app->make(DatabaseManager::class);
            foreach (array_keys($db->getConnections()) as $name) {
                $db->disconnect((string) $name);
            }
        }

        if ($app->resolved(RedisManager::class)) {
            $redis = $app->make(RedisManager::class);
            // Null until a first connection is made, whatever the docblock promises; the cast is that case.
            foreach (array_keys((array) $redis->connections()) as $name) {
                $connection = $redis->connection((string) $name);
                if (method_exists($connection, 'disconnect')) {
                    $connection->disconnect();
                }
                $redis->purge((string) $name);
            }
        }
    }
}
