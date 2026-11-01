<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\EnumCaseValues;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Workbench\App\Enums\WidgetStatus;
use Workbench\App\Models\Gadget;

/** A filter on an enum-cast column whose default is a case, which Spatie binds as its backing value. */
final class GadgetFilterController
{
    /**
     * @return LengthAwarePaginator<int, Gadget>
     */
    public function index(): LengthAwarePaginator
    {
        return QueryBuilder::for(Gadget::class)
            ->allowedFilters([
                AllowedFilter::exact('status')->default(WidgetStatus::Published),
            ])
            ->paginate(20);
    }
}
