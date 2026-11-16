<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Pipeline\BuildWorkers;

/**
 * Turns an out-of-memory fatal during a console build into an explanation. PHP can't catch memory
 * exhaustion, so shutdown is the only place left to say anything: it recognises the fatal by message and
 * names both levers — the ceiling itself, and how wide the analyser is sent. The second is a key the shipped
 * template leaves commented out, and its default is WIDER than the `['app']` that used to ship, so the
 * wording says what the default is and that narrowing means writing the key rather than editing a line
 * already in the reader's file.
 *
 * The one invariant is shutdown order. PHP runs shutdown functions in the order they were registered and
 * stops at the first that fails, and the framework's error handler — registered while the application
 * bootstraps, before any build arms this — reports the fatal first, on a heap that is already full. So
 * {@see shutdown()} is registered from the package's autoload file, ahead of everything, and only gives the
 * shutdown work room and queues the notice behind it. Nor may it make a function's first call before it has
 * raised the ceiling: PHP takes memory for that, and a heap that ran out may have none to give.
 *
 * Console only, armed at most once, and spoken only by the process that armed it — never by a worker
 * forked from the build, which inherits the shutdown function too ({@see BuildWorkers}). A normal
 * shutdown, or any other fatal, prints nothing.
 */
final class OutOfMemoryNotice
{
    /**
     * How far past the exhausted ceiling the shutdown work may go. Reporting a fatal is not small: the
     * console renderer highlighting the file the fatal names peaks near 64M for PHPStan's function map.
     */
    private const int HEADROOM = 256 * 1024 * 1024;

    /** Freed first, so raising the ceiling has the few bytes it needs on a full heap. */
    private const int RESERVE = 32 * 1024;

    private static ?int $armedBy = null;

    private static string $text = '';

    private static ?string $reserve = null;

    public static function arm(): void
    {
        if (self::$armedBy !== null || PHP_SAPI !== 'cli') {
            return;
        }

        self::$armedBy = (int) getmypid();
        // Written now, while there is memory to spare: the shutdown that reads it has none, and building the
        // text there would load classes the build may never have needed.
        self::$text = self::text((string) ini_get('memory_limit'));
        // The shutdown runs once now, finding no exhaustion, so everything it calls before raising the ceiling
        // has had its first call while there is memory for one.
        self::$reserve = '';
        self::shutdown();
        self::$reserve = str_repeat("\0", self::RESERVE);
    }

    /**
     * Runs before every other shutdown function (`src/shutdown.php`). On exhaustion in the process that
     * armed the notice it raises the ceiling by {@see HEADROOM}, so the framework can report the fatal, and
     * queues the notice last, behind whatever reports it.
     */
    public static function shutdown(): void
    {
        // Once, and only where armed: the reserve is gone after the first call.
        if (self::$armedBy !== getmypid() || self::$reserve === null) {
            return;
        }

        self::$reserve = null;

        if (! self::isExhaustion(error_get_last())) {
            return;
        }

        ini_set('memory_limit', (string) (memory_get_usage(true) + self::HEADROOM));

        // Queued twice over: reporting the fatal can leave shutdown work of its own behind it — Collision's
        // error page does, when it first renders — and the notice belongs after that too.
        $text = self::$text;
        register_shutdown_function(static function () use ($text): void {
            register_shutdown_function(static function () use ($text): void {
                fwrite(STDERR, $text);
            });
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
