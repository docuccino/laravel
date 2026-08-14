<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

/**
 * Turns an out-of-memory fatal during in-process inference into an explanation. PHP can't catch memory
 * exhaustion, so a shutdown handler is the only place left to say anything: it recognises the fatal by
 * message and names both levers — the ceiling itself, and how wide `project_paths` sends the analyser.
 *
 * Console only, and armed at most once; a normal shutdown, or any other fatal, prints nothing.
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
        $limit = ini_get('memory_limit');

        register_shutdown_function(static function () use ($limit): void {
            if (self::isExhaustion(error_get_last())) {
                fwrite(STDERR, self::text($limit));
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
        return <<<TEXT

            Docuccino ran out of memory while analyzing your code.

            In-process inference runs PHPStan inside this process, so it is bound by this process's
            memory_limit (currently {$limit}). Two levers:

              * Raise the ceiling — set docuccino.engine.memory_limit (e.g. '2G'), or pass
                --memory-limit=2G to this command.
              * Narrow the analysis — docuccino.engine.project_paths decides how far interprocedural
                descent goes, and a wide value costs memory. Vendor code is never analyzed.

            Set DOCUCCINO_ENGINE=null to document from docblocks and attributes alone.

            TEXT;
    }
}
