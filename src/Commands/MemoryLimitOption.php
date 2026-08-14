<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Laravel\Engine\ConsoleBuild;
use Docuccino\Laravel\Engine\MemoryLimit;
use Illuminate\Console\Events\CommandStarting;

/**
 * Lands `--memory-limit` in the engine config, making the flag and `docuccino.engine.memory_limit` one
 * lever with the flag winning, and marks the run as a {@see ConsoleBuild} — the same "one of our commands
 * is starting" fact answers both, and this is the only place it is known.
 *
 * It has to happen this early: the engine reads its ceiling when the container builds it, and that happens
 * while a command's dependencies are injected — before any `handle()` body runs. So the value is read off
 * the raw input, which `CommandStarting` is the last hook to reach in time.
 *
 * @see MemoryLimit for why the limit only ever raises
 */
final class MemoryLimitOption
{
    public static function capture(CommandStarting $event): void
    {
        if (! str_starts_with($event->command ?? '', 'docuccino:')) {
            return;
        }

        ConsoleBuild::mark();

        $limit = $event->input->getParameterOption('--memory-limit', '');
        $limit = is_string($limit) ? trim($limit) : '';

        if ($limit !== '') {
            config(['docuccino.engine.memory_limit' => $limit]);
        }
    }
}
