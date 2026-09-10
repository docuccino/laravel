<?php

declare(strict_types=1);

namespace Workbench\App\Queries;

use Workbench\App\Exceptions\LedgerRejectedException;

/**
 * A query object a controller calls. `review()` rethrows what it caught, so nothing on the path to that
 * `throw` builds the exception and the class it names answers two statuses — which is the shape a status
 * genuinely cannot be read from.
 */
final class LedgerReviewQuery
{
    /**
     * @return array{ledger: string, entries: int}
     *
     * @throws LedgerRejectedException
     */
    public function review(string $ledger): array
    {
        try {
            return $this->post($ledger);
        } catch (LedgerRejectedException $rejected) {
            report($rejected);

            throw $rejected;
        }
    }

    /**
     * @return array{ledger: string, entries: int}
     *
     * @throws LedgerRejectedException
     */
    private function post(string $ledger): array
    {
        if ($ledger === 'closed') {
            throw LedgerRejectedException::stale($ledger);
        }

        return ['ledger' => $ledger, 'entries' => 0];
    }
}
