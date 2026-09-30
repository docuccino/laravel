<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;

/**
 * Marks the process as one started to run a Docuccino command, which is what lets a build move the memory
 * ceiling, arm a shutdown notice and fork workers: all three reach the whole process, so only a process that
 * is the build's own may have them. `PHP_SAPI` cannot tell — Octane serves HTTP under the `cli` SAPI — and a
 * command's name cannot either, since `Artisan::call()` from a job, a request or another command starts one
 * inside a process that serves other work.
 */
final class ConsoleBuild
{
    /** Marks the rest of this process's run as a console build, where `$input` is the command line it was started with. */
    public static function markStartedBy(InputInterface $input): bool
    {
        if (! self::isCommandLine($input)) {
            return false;
        }

        app()->instance(self::class, new self);

        return true;
    }

    public static function active(): bool
    {
        return app()->bound(self::class);
    }

    /**
     * Whether `$input` is this process's own command line. The artisan binary hands the command it starts an
     * ArgvInput; one called in-process gets an ArrayInput, or a StringInput, which borrows ArgvInput's parser.
     */
    private static function isCommandLine(InputInterface $input): bool
    {
        return $input instanceof ArgvInput && ! $input instanceof StringInput;
    }
}
