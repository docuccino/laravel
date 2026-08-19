<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diagnostics\AcceptedCodes;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Config\AcceptedDiagnostics;
use Illuminate\Console\Command;

/**
 * The `--fail-on` policy shared by the commands: a floor on {@see Severity}, where anything reported
 * at that severity or louder makes the run exit non-zero, and `none` never fails.
 *
 * The floor reaches `info` and `hint` as well as `warning` and `error`, because `info` is where the
 * build reports that it had to widen — an unrecoverable payload, a model with no readable columns, a
 * validation rule it could not read. Those are the reports a pipeline gating on inference certainty
 * wants, and no other value on this option reaches them.
 *
 * A value we don't recognise is rejected by {@see validateFailOn()} rather than coerced: coercing a
 * typo would answer "never fail", which silently removes the gate the flag was added to CI to be.
 *
 * The one thing that carves into the floor is `diagnostics.accept` ({@see AcceptedCodes}), and it
 * carves into the exit code and nothing else. This is also where the two reports acceptance owes the
 * reader are raised: a code it could not cover, and an entry this run proved does nothing.
 *
 * @mixin Command
 */
trait FailsOnSeverity
{
    /** @var list<string> Loudest first, so the printed list reads as the ladder it is. */
    private const FAIL_ON_VALUES = ['none', 'error', 'warning', 'info', 'hint'];

    /**
     * Rendering the diagnostics is how a gating command shows its work, and what it printed is what
     * a stale acceptance is measured against. {@see RendersDiagnostics} is the implementation every
     * command that gates already uses.
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    abstract protected function renderDiagnostics(string $document, array $diagnostics): void;

    /** @return list<string> */
    abstract protected function printedCodes(): array;

    /**
     * The gate over a bare list.
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    protected function failsOnAny(array $diagnostics): bool
    {
        $floor = Severity::tryFrom($this->failOn());

        return $floor !== null && AcceptedDiagnostics::read()->fails($diagnostics, $floor);
    }

    /**
     * A document's diagnostics with the acceptance notes it earned folded in, in the collector's
     * order so the console stays byte-stable.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return list<Diagnostic>
     */
    protected function withAcceptanceNotes(array $diagnostics): array
    {
        $notes = $this->refusedAcceptances($diagnostics);

        if ($notes === []) {
            return $diagnostics;
        }

        $collector = new DiagnosticCollector;
        $collector->addAll([...$diagnostics, ...$notes]);

        return $collector->sorted();
    }

    /**
     * Codes this run reported that acceptance was never going to cover. Silence here would leave a
     * reader with a failing build and a config file that says the failure was accepted.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return list<Diagnostic>
     */
    private function refusedAcceptances(array $diagnostics): array
    {
        $notes = [];

        foreach (AcceptedDiagnostics::read()->refused($diagnostics) as $code) {
            $notes[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'config.accept-refused',
                message: sprintf(
                    "diagnostics.accept names '%s', which this build reported as an error; acceptance never covers an error, so it still fails the run.",
                    $code,
                ),
                help: 'Fix what the error reports, then drop the entry — it does nothing while the code is an error.',
            );
        }

        return $notes;
    }

    /**
     * Reports the acceptance entries this run proved do nothing, and folds them into the exit code.
     * Called once, after every document, because an entry is only stale when NO document reported it.
     *
     * A run narrowed to one document says nothing: it cannot tell an entry nothing reports from one
     * the document it skipped reports on every build.
     */
    protected function reportStaleAcceptances(int $exit): int
    {
        if (is_string($this->argument('document'))) {
            return $exit;
        }

        $stale = [];

        foreach (AcceptedDiagnostics::read()->unused($this->printedCodes()) as $code) {
            $stale[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'config.accept-unused',
                message: sprintf("diagnostics.accept names '%s', which nothing reported in this build.", $code),
                help: 'Delete the entry: the cause is fixed, or the code is misspelled. An acceptance nobody can see expire is the next stale config key.',
            );
        }

        if ($stale === []) {
            return $exit;
        }

        $this->renderDiagnostics('config/docuccino.php', $stale);

        return $this->failsOnAny($stale) ? self::FAILURE : $exit;
    }

    /** False (after printing why) when `--fail-on` names something we don't know. */
    protected function validateFailOn(): bool
    {
        if (in_array($this->failOn(), self::FAIL_ON_VALUES, true)) {
            return true;
        }

        $this->error(sprintf(
            'Unknown --fail-on "%s"; expected one of: %s.',
            $this->failOn(),
            implode(', ', self::FAIL_ON_VALUES),
        ));

        return false;
    }

    /** The flag as given; `--fail-on` with no value at all is the same as not passing it. */
    private function failOn(): string
    {
        $value = $this->option('fail-on');

        return is_string($value) ? $value : 'none';
    }
}
