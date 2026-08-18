<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Laravel\Support\TerminalText;
use Illuminate\Console\Command;

/**
 * Prints a document's diagnostics grouped by route signature, relying on the order
 * {@see DiagnosticCollector} already imposes so console output is byte-stable across runs.
 *
 * A producer states the truth and this decides how to show it, so every value lifted out of the
 * application goes through {@see TerminalText} on the way out.
 *
 * The CLI is the primary channel a diagnostic reaches its author on, so a diagnostic's `help` — the
 * "what to change" half — is printed here alongside the message rather than left to `toArray()`.
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
        $this->line(sprintf('<comment>Diagnostics for %s:</comment>', TerminalText::of($document)));

        $current = "\0";
        foreach ($diagnostics as $diagnostic) {
            $group = $diagnostic->routeSignature ?? '(document)';
            if ($group !== $current) {
                $current = $group;
                $this->line('  '.TerminalText::of($group));
            }

            $this->line(sprintf(
                '    [%s] %s: %s',
                $diagnostic->severity->value,
                TerminalText::of($diagnostic->code),
                TerminalText::of($diagnostic->message),
            ));

            $this->renderHelp($diagnostic->help);
        }
    }

    /**
     * The "what to change" half, under the line it belongs to and dimmer than it, so codes still scan
     * down the left edge.
     *
     * Help is the one value here allowed to carry line breaks: they become layout rather than the escape
     * {@see TerminalText} would otherwise show. That stays safe because every help line is indented past
     * any diagnostic line, so text lifted out of an application can add lines but never forge one.
     */
    private function renderHelp(?string $help): void
    {
        if ($help === null || trim($help) === '') {
            return;
        }

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $help)) as $line) {
            $line = rtrim($line);

            $this->line($line === '' ? '' : sprintf('      <fg=gray>%s</>', TerminalText::of($line)));
        }
    }
}
