<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Pipeline\BuildWorkers;

/**
 * Turns an out-of-memory fatal during a console build into an explanation. PHP can't catch memory
 * exhaustion, so a shutdown handler is the only place left to say anything: it recognises the fatal by
 * message and names both levers — the ceiling itself, and how wide the analyser is sent. The second is a
 * key the shipped template leaves commented out, and its default is WIDER than the `['app']` that used to
 * ship, so the wording says what the default is and that narrowing means writing the key rather than
 * editing a line already in the reader's file.
 *
 * Console only, armed at most once, and spoken only by the process that armed it — never by a worker
 * forked from the build, which inherits the shutdown function too ({@see BuildWorkers}). A normal
 * shutdown, or any other fatal, prints nothing.
 */
final class OutOfMemoryNotice
{
    private static bool $armed = false;

    public static function arm(): void
    {
        if (self::$armed || PHP_SAPI !== 'cli') {
            return;
        }

        self::$armed = true;
        // Written now, while there is memory to spare: the shutdown that reads it has none, and building the
        // text there would load classes the build may never have needed.
        $text = self::text((string) ini_get('memory_limit'));
        $armedBy = getmypid();

        register_shutdown_function(static function () use ($text, $armedBy): void {
            if ($armedBy === getmypid() && self::isExhaustion(error_get_last())) {
                fwrite(STDERR, $text);
            }
        });
    }

    /**
     * Whether a shutdown error is memory exhaustion rather than any other fatal. PHP reports it as an
     * `E_ERROR` whose message opens with the allocation that didn't fit, so the message is the only tell.
     *
     * @param  array{type: int, message: string, file: string, line: int}|null  $error
     */
    public static function isExhaustion(?array $error): bool
    {
        return $error !== null
            && $error['type'] === E_ERROR
            && str_contains($error['message'], 'Allowed memory size');
    }

    /** The guidance printed on exhaustion; pure, so its wording is testable. */
    public static function text(string $limit): string
    {
        $file = ConfigFile::NAME;

        return <<<TEXT

            Docuccino ran out of memory building your documentation.

            The build runs inside this process, in-process inference and PHPStan with it, so it is
            bound by this process's memory_limit (currently {$limit}). Two levers:

              * Raise the ceiling — set engine.memory_limit in {$file} (e.g. '2G'), or pass
                --memory-limit=2G to this command.
              * Narrow the analysis — engine.project_paths, in the same file, bounds interprocedural
                descent. It is unset by default, which descends into every PSR-4 source root your
                composer.json declares, so writing it and naming fewer of them costs memory back.
                Vendor code is never analyzed.

            Set DOCUCCINO_ENGINE=null to document from docblocks and attributes alone.

            TEXT;
    }
}
