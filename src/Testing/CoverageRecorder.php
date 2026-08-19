<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Contract\Coverage\CoverageReport;
use Docuccino\Laravel\Testing\Contracts\ContractObserver;

/**
 * Which operations the run actually touched, by stable id — never by path, for the reason
 * {@see CoverageReport} gives.
 *
 * It lives for one PHP process, and {@see ParallelRun} is why that is safe rather than misleading —
 * the coverage assertions refuse outright under a parallel runner instead of reporting the operations
 * this worker happened not to see as never exercised.
 */
final class CoverageRecorder implements ContractObserver
{
    /** @var array<string, true> */
    private array $ids = [];

    public function observed(ObservedExchange $exchange): void
    {
        $id = $exchange->operationId();

        if ($id !== null) {
            $this->record($id);
        }
    }

    public function record(string $id): void
    {
        $this->ids[$id] = true;
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

    public function forget(): void
    {
        $this->ids = [];
    }
}
