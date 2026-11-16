<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DescribedNotes;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Workbench\App\Models\Gadget;

/** A filter on an enum-cast column that also takes `null`, described by its author and not. */
final class DescribedFilterController
{
    /**
     * @return LengthAwarePaginator<int, Gadget>
     */
    #[QueryParameter('filter[status]', description: 'The state to list.')]
    public function index(): LengthAwarePaginator
    {
        return QueryBuilder::for(Gadget::class)
            ->allowedFilters([
                AllowedFilter::exact('status')->nullable(),
            ])
            ->paginate(20);
    }

    /**
     * @return LengthAwarePaginator<int, Gadget>
     */
    public function browse(): LengthAwarePaginator
    {
        return QueryBuilder::for(Gadget::class)
            ->allowedFilters([
                AllowedFilter::exact('status')->nullable(),
            ])
            ->paginate(20);
    }
}
