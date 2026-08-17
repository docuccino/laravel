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
        }
    }
}
