<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diagnostics\AcceptedCodes;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Core\Diagnostics\DiagnosticDocs;
use Docuccino\Laravel\Config\AcceptedDiagnostics;
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
 * A diagnostic `diagnostics.accept` covers prints exactly as any other does ({@see AcceptedCodes}),
 * marked `accepted` and counted in a closing line.
 *
 * @mixin Command
 */
trait RendersDiagnostics
{
    /** @var array<string, true> Every code this run printed, which is what {@see FailsOnSeverity} measures a stale acceptance against. */
    private array $printedCodes = [];

    /** @var array<string, true> Codes whose reference link this run has already shown. */
    private array $linkedCodes = [];

    /**
     * @param  list<Diagnostic>  $diagnostics
     */
    protected function renderDiagnostics(string $document, array $diagnostics): void
    {
        foreach ($diagnostics as $diagnostic) {
            $this->printedCodes[$diagnostic->code] = true;
        }

        if ($diagnostics === []) {
            return;
        }

        $accepted = AcceptedDiagnostics::read();

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
                '    [%s%s] %s: %s',
                $diagnostic->severity->value,
                $accepted->accepts($diagnostic) ? ', accepted' : '',
                TerminalText::of($diagnostic->code),
                TerminalText::of($diagnostic->message),
            ));

            $this->renderHelp($diagnostic->help);
            $this->renderReference($diagnostic->code);
        }

        $this->renderAccepted($accepted->tally($diagnostics));
    }

    /**
     * Every code this run printed. Printing is the widest net there is — a diagnostic the reader
     * never saw cannot be the one their acceptance was for — so an entry missing from here is one
     * nothing in the run reported.
     *
     * @return list<string>
     */
    protected function printedCodes(): array
    {
        return array_keys($this->printedCodes);
    }

    /**
     * What acceptance quieted here, so the list stays visible in the output it is quieting — a
     * suppression nobody reads is the one that outlives what it was for.
     *
     * @param  array<string, int>  $tally  by code, so the line reads the same on every run
     */
    private function renderAccepted(array $tally): void
    {
        if ($tally === []) {
            return;
        }

        $codes = [];
        foreach ($tally as $code => $count) {
            $codes[] = sprintf('%s (%d)', TerminalText::of($code), $count);
        }

        $this->line(sprintf('  <fg=gray>Accepted, so --fail-on ignores them: %s</>', implode(', ', $codes)));
    }

    /**
     * Where the code is written up, under its first appearance only. A build that reports one code two
     * hundred times has one link to follow, not two hundred: the line is worth its place because it is
     * proportional to the codes a reader actually met, never to how loudly they fired.
     */
    private function renderReference(string $code): void
    {
        if (isset($this->linkedCodes[$code])) {
            return;
        }

        $this->linkedCodes[$code] = true;

        $this->line(sprintf('      <fg=gray>%s</>', DiagnosticDocs::urlFor($code)));
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
