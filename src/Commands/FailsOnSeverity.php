<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Config\ConfigFile;
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
 * "Anything reported" is the whole of it: the set the floor reads is everything the run PRINTED
 * ({@see withSeverityGate()}), so a command cannot show the reader a report the gate cannot see. The
 * one exception runs the other way — a report a command treats as fatal on its own terms, such as an
 * artifact that is not a valid document of its own format, still fails at `--fail-on=none`.
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

    /** @return list<Diagnostic> */
    abstract protected function printedDiagnostics(): array;

    /**
     * The floor itself, over a bare list. Private because the only list it may be asked about is the
     * one the run printed — a caller picking its own is the hole this trait now exists to close.
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    private function failsOnAny(array $diagnostics): bool
    {
        $floor = Severity::tryFrom($this->failOn());

        return $floor !== null && AcceptedDiagnostics::read()->fails($diagnostics, $floor);
    }

    /**
     * The command's exit code: whatever the run itself decided, and then the floor over EVERYTHING it
     * printed — the build's reports, an emitter's, and the config reports raised before either.
     *
     * The set is the one {@see RendersDiagnostics} recorded rather than a list each call site
     * remembers to pass on, because the two could differ and did: a channel with a renderer and no
     * gate printed a warning, exited zero under `--fail-on=warning`, and printed "Accepted, so
     * --fail-on ignores them" over a code the gate was never going to be asked about.
     */
    protected function withSeverityGate(int $exit): int
    {
        return $exit === self::FAILURE || $this->failsOnAny($this->printedDiagnostics())
            ? self::FAILURE
            : self::SUCCESS;
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
     * Reports the acceptance entries this run proved do nothing. Called once, after every document,
     * because an entry is only stale when NO document reported it — and before
     * {@see withSeverityGate()}, which is what folds these into the exit code along with the rest.
     *
     * A run narrowed to one document says nothing: it cannot tell an entry nothing reports from one
     * the document it skipped reports on every build.
     */
    protected function reportStaleAcceptances(): void
    {
        if (is_string($this->argument('document'))) {
            return;
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

        $this->renderDiagnostics(ConfigFile::NAME, $stale);
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
