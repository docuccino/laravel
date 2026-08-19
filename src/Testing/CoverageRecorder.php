<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Contract\Coverage\CoverageLog;
use Docuccino\Core\Contract\Coverage\CoverageReport;
use Docuccino\Laravel\Support\CoverageLogPath;
use Docuccino\Laravel\Testing\Contracts\ContractObserver;

/**
 * Which operations this process touched, by stable id — never by path, for the reason
 * {@see CoverageReport} gives.
 *
 * A process is all it can speak for, which is the whole shape of the feature: a worker sees its own
 * share of the suite and nothing else, and no worker can know when the others have finished. So
 * {@see logTo()} writes what this one exercised to a file of its own, and `docuccino:coverage` unions
 * the files after the run — where the whole suite has, by then, definitely finished.
 */
final class CoverageRecorder implements ContractObserver
{
    /** @var array<string, true> */
    private array $ids = [];

    private bool $logging = false;

    private ?string $directory = null;

    private ?CoverageLog $log = null;

    public function observed(ObservedExchange $exchange): void
    {
        $id = $exchange->operationId();

        if ($id !== null) {
            $this->record($id);
        }
    }

    /**
     * Record one exercised operation, by id.
     *
     * Anything that is not an operation id is dropped rather than recorded: this is public, a log line
     * is held to the id shape when it is read back, and a caller passing a stray string would otherwise
     * condemn the whole file its process wrote. Nothing is lost by dropping it — an id that is not an
     * operation's matches no operation in the report either.
     */
    public function record(string $id): void
    {
        if (isset($this->ids[$id]) || ! CoverageLog::isId($id)) {
            return;
        }

        $this->ids[$id] = true;

        if ($this->logging) {
            $this->log()->append([$id]);
        }
    }

    /**
     * Start writing this process's ids to a coverage log, for `docuccino:coverage` to merge.
     *
     * Pass a directory to override `coverage.log`. Each id is appended the first time it is seen, so a
     * suite that crashes half way still leaves behind what it had reached.
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
     * Sorted, so two runs that exercised the same operations hand back the same list whatever order
     * the tests ran in.
     *
     * @return list<string>
     */
    public function exercised(): array
    {
        $ids = array_keys($this->ids);
        sort($ids);

        return $ids;
    }

    /** Forget what this process recorded. What it already wrote to its log stays written. */
    public function forget(): void
    {
        $this->ids = [];
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
