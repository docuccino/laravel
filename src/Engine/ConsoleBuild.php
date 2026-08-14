<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

/**
 * Marks the process as running one of Docuccino's own console commands. Bound by the `CommandStarting`
 * listener and absent everywhere else, which is how the engine knows it may move the process memory
 * ceiling and arm a shutdown notice: a web request must never do either, and `PHP_SAPI` cannot tell the
 * two apart — Octane serves HTTP under the `cli` SAPI, so `runningInConsole()` reads true there.
 */
final class ConsoleBuild
{
    /** Marks the rest of this command's run as a console build. */
    public static function mark(): void
    {
        app()->instance(self::class, new self);
    }

    public static function active(): bool
    {
        return app()->bound(self::class);
    }
}
