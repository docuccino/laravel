<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\QueryBuilder;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Workbench\App\Enums\WidgetStatus;
use Workbench\App\Models\Gadget;

/**
 * Filter defaults in each shape Spatie applies one: a list, a delimited string, a case, null, and a
 * scalar, on filters whose values are published as a list and as a single value.
 */
final class FilterDefaultsController
{
    /**
     * @return LengthAwarePaginator<int, Gadget>
     */
    public function index(): LengthAwarePaginator
    {
        return QueryBuilder::for(Gadget::class)
            ->allowedFilters([
                AllowedFilter::exact('status')->default('published'),
                AllowedFilter::exact('statuses', 'status')->default([WidgetStatus::Draft, WidgetStatus::Published]),
                AllowedFilter::exact('status_list', 'status')->default('draft,published'),
                AllowedFilter::exact('unset_status', 'status')->default(null),
                AllowedFilter::exact('active')->default(true),
                AllowedFilter::exact('score')->default(10),
                AllowedFilter::exact('scores', 'score')->default([10, 20]),
                AllowedFilter::partial('public_id')->default('gad'),
                AllowedFilter::scope('min_score')->default(5),
            ])
            ->paginate(20);
    }
}
