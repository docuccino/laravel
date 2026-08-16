<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Core\Support\PlainText;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Prints a document's diagnostics grouped by route signature, relying on the order
 * {@see DiagnosticCollector} already imposes so console output is byte-stable across runs.
 *
 * This is the only place a diagnostic is written to a terminal, so it is the one place that escapes
 * for one: a producer states the truth and this decides how to show it ({@see plain()}). Escaping at
 * the producer instead would follow the same diagnostic onto the JSON and document paths, where
 * `json_encode` already escapes and a second pass only garbles it.
 *
 * @mixin Command
 */
trait RendersDiagnostics
{
    /**
     * @param  list<Diagnostic>  $diagnostics
     */
    protected function renderDiagnostics(string $document, array $diagnostics): void
    {
        if ($diagnostics === []) {
            return;
        }

        $this->newLine();
        $this->line(sprintf('<comment>Diagnostics for %s:</comment>', self::plain($document)));

        $current = "\0";
        foreach ($diagnostics as $diagnostic) {
            $group = $diagnostic->routeSignature ?? '(document)';
            if ($group !== $current) {
                $current = $group;
                $this->line('  '.self::plain($group));
            }

            $this->line(sprintf(
                '    [%s] %s: %s',
                $diagnostic->severity->value,
                self::plain($diagnostic->code),
                self::plain($diagnostic->message),
            ));
        }
    }

    /**
     * A string lifted out of an application, as a terminal may show it. {@see PlainText} covers what
     * steers a terminal directly; the escaping over it covers Symfony's own markup, which `line()`
     * would otherwise interpret — the formatter undoes it as it writes, so a legitimate
     * `array<int, string>` in a message still reads as written.
     */
    private static function plain(string $value): string
    {
        return OutputFormatter::escape(PlainText::of($value));
    }
}
