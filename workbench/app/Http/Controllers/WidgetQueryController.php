<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Workbench\App\Models\Form;

/**
 * A Spatie Query Builder list endpoint mirroring the Spike-B pattern (allow-lists + pagination). The
 * workbench stub engine scripts the trace of this action deterministically, so the golden exercises
 * the Query Builder integration end-to-end (filter[status]/filter[name] + sort + page/per_page).
 */
final class WidgetQueryController
{
    /**
     * List forms, filterable and sortable via the query string.
     *
     * @return LengthAwarePaginator<int, Form>
     */
    public function index(): LengthAwarePaginator
    {
        return QueryBuilder::for(Form::class)
            ->allowedFilters(['name', AllowedFilter::exact('status')])
            ->allowedSorts(['name', 'created_at'])
            ->defaultSort('name')
            ->paginate(20);
    }
}
