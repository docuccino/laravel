<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\QueryBuilder;
use Workbench\App\Models\Ledger;

/**
 * A Query Builder list endpoint whose allow-lists are an include set and a sparse fieldset, so the
 * golden locks both enums: the Count/Exists forms an include mints, the group a `type.` prefix makes,
 * the SDK member names each value mints, and the per-value prose (an allow-list comment, a relation
 * docblock, a column summary). The workbench stub engine scripts the trace of this action.
 */
final class LedgerQueryController
{
    /**
     * List ledgers, with related resources and a sparse fieldset selectable via the query string.
     *
     * @return LengthAwarePaginator<int, Ledger>
     */
    public function index(): LengthAwarePaginator
    {
        return QueryBuilder::for(Ledger::class)
            ->allowedIncludes([
                // The forms filed against this ledger.
                'entries',
                'auditor',
            ])
            ->allowedFields(['reference', 'opened_at', 'entries.id'])
            ->paginate(20);
    }
}
