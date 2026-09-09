<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Laravel\Config\BuildConfig;
use Docuccino\Laravel\Engine\ConsoleBuild;
use Docuccino\Laravel\Engine\MemoryLimit;
use Illuminate\Console\Events\CommandStarting;

/**
 * Records `--memory-limit` for this run, making the flag and the configured `engine.memory_limit` one
 * lever with the flag winning ({@see BuildConfig::engine()} is where the two meet), and marks the run
 * as a {@see ConsoleBuild} — the same "one of our commands is starting" fact answers both, and this is
 * the only place it is known.
 *
 * It has to happen this early: the engine reads its ceiling when the container builds it, and that happens
 * while a command's dependencies are injected — before any `handle()` body runs. So the value is read off
 * the raw input, which `CommandStarting` is the last hook to reach in time.
 *
 * Held on the container beside the console marker, and for the same reason: a ceiling asked for on the
 * command line is a fact about this PROCESS, where the configured one is a fact about the project.
 *
 * @see MemoryLimit for why the limit only ever raises
 */
final readonly class MemoryLimitOption
{
    public function __construct(public string $limit) {}

    public static function capture(CommandStarting $event): void
    {
        if (! str_starts_with($event->command ?? '', 'docuccino:')) {
            return;
        }

        ConsoleBuild::mark();

        $limit = $event->input->getParameterOption('--memory-limit', '');
        $limit = is_string($limit) ? trim($limit) : '';

        if ($limit !== '') {
            app()->instance(self::class, new self($limit));
        }
    }

    /** The ceiling this run asked for on the command line, or null when it asked for none. */
    public static function requested(): ?string
    {
        $container = app();

        return $container->bound(self::class) ? $container->make(self::class)->limit : null;
    }
}
