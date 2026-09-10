<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Workbench\App\Exceptions\LedgerRejectedException;
use Workbench\App\Queries\LedgerReviewQuery;

/**
 * Three error responses that all publish a 5xx-or-not answer for different reasons: one the build could
 * not read a status for, one it read, and one that is no HTTP error at all.
 */
final class LedgerController
{
    /** Review a ledger. */
    public function review(string $ledger, LedgerReviewQuery $query): JsonResponse
    {
        return response()->json($query->review($ledger));
    }

    /** Post a ledger. */
    public function post(string $ledger): JsonResponse
    {
        throw LedgerRejectedException::locked($ledger);
    }

    /** Reconcile a ledger. */
    public function reconcile(string $ledger): JsonResponse
    {
        throw new RuntimeException('The ledger service is not configured.');
    }
}
