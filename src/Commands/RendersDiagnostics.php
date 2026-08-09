<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Illuminate\Console\Command;

/**
 * Prints a document's diagnostics grouped by route signature, relying on the order
 * {@see DiagnosticCollector} already imposes so console output is byte-stable across runs.
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
        $this->line(sprintf('<comment>Diagnostics for %s:</comment>', $document));

        $current = "\0";
        foreach ($diagnostics as $diagnostic) {
            $group = $diagnostic->routeSignature ?? '(document)';
            if ($group !== $current) {
                $current = $group;
                $this->line('  '.$group);
            }
            $this->line(sprintf('    [%s] %s: %s', $diagnostic->severity->value, $diagnostic->code, $diagnostic->message));
        }
    }
}
