<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Contract\Coverage\CoverageLog;
use Docuccino\Core\Contract\Coverage\CoverageReport;
use Docuccino\Laravel\Support\CoverageLogPath;
use Docuccino\Laravel\Testing\Contracts\ContractObserver;

/**
 * Which documented responses and webhook deliveries this process exercised, by stable operation id and
 * status — never by path, for the reason {@see CoverageReport} gives.
 *
 * A process is all it can speak for, which is the whole shape of the feature: a worker sees its own
 * share of the suite and nothing else, and no worker can know when the others have finished. So
 * {@see logTo()} writes what this one exercised to a file of its own, and `docuccino:coverage` unions
 * the files after the run — where the whole suite has, by then, definitely finished.
 */
final class CoverageRecorder implements ContractObserver
{
    /** @var array<string, true> */
    private array $entries = [];

    private bool $logging = false;

    private ?string $directory = null;

    private ?CoverageLog $log = null;

    /**
     * A response counts only where the response half ran AND agreed with the contract — read off the
     * result, never off the bare status. Two cases hang on that, and both are the too-generous number
     * this whole report exists to stop: `assertValidRequest()` checks nothing that came back, and an
     * assertion that FAILED has proved the response does not keep the promise, which is the opposite of
     * having exercised it. Either records the operation as reached and no response of it.
     *
     * A pass carrying a NOTE — a `text/csv` body, a media type the contract gives no schema — does
     * count. The exchange happened and the documented response answered it; what could not be checked
     * is a gap in the DOCUMENT, and there is no assertion a suite could write that would close it. Not
     * crediting it would leave such an endpoint permanently uncoverable and a 100% floor unreachable
     * for a defect the operator cannot fix from the test side. The document's own weakness is a
     * separate report, and the check says so in the note either way.
     */
    public function observed(ObservedExchange $exchange): void
    {
        $id = $exchange->operationId();

        if ($id === null) {
            return;
        }

        $response = $exchange->result->response;

        $this->record($id, $response !== null && $response->ok() ? $exchange->status() : null);
    }

    /**
     * Record one exercised response, by operation id and the status it answered. Pass no status for an
     * operation the run reached without proving any response of it.
     *
     * Anything that is not an entry is dropped rather than recorded: this is public, a log line is held
     * to the entry shape when it is read back, and a caller passing a stray string would otherwise
     * condemn the whole file its process wrote. Nothing is lost by dropping it — an id that is not an
     * operation's matches no operation in the report either.
     */
    public function record(string $id, ?int $status = null): void
    {
        $entry = CoverageLog::entry($id, $status);

        if ($entry === null || isset($this->entries[$entry])) {
            return;
        }

        $this->entries[$entry] = true;

        if ($this->logging) {
            $this->log()->append([$entry]);
        }
    }

    /**
     * Start writing this process's entries to a coverage log, for `docuccino:coverage` to merge.
     *
     * Pass a directory to override `coverage.log`. Each entry is appended the first time it is seen, so
     * a suite that crashes half way still leaves behind what it had reached.
     */
    public function logTo(?string $directory = null): self
    {
        $this->logging = true;
        $this->directory = $directory;
        $this->log = null;

        return $this;
    }

    /** The file this process writes, or null when it is not logging — for a bootstrap that wants to say so. */
    public function logPath(): ?string
    {
        return $this->logging ? $this->log()->path() : null;
    }

    /**
     * What this process exercised, as coverage log entries. Sorted, so two runs that exercised the same
     * responses and deliveries hand back the same list whatever order the tests ran in.
     *
     * @return list<string>
     */
    public function exercised(): array
    {
        $entries = array_keys($this->entries);
        sort($entries);

        return $entries;
    }

    /** Forget what this process recorded. What it already wrote to its log stays written. */
    public function forget(): void
    {
        $this->entries = [];
    }

    /**
     * Resolved on first use, because a test bootstrap constructs the recorder before there is a
     * container to ask where the directory is.
     */
    private function log(): CoverageLog
    {
        return $this->log ??= CoverageLog::for(
            CoverageLogPath::resolve(ApiContract::build()->config(), base_path(), $this->directory),
            ParallelRun::worker(),
        );
    }
}
