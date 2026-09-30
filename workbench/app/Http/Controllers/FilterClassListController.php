<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Workbench\App\Filters\BandFilter;
use Workbench\App\Filters\DocumentedFilter;
use Workbench\App\Filters\MistypedFilter;
use Workbench\App\Models\Gadget;

/**
 * A Query Builder list endpoint whose filters are custom filter CLASSES documenting themselves: one
 * whose attribute leaves the name to its registration, one whose attribute writes a name that is
 * ignored, and one whose attribute PHP cannot construct. Routed ad-hoc, so no committed golden churns.
 */
final class FilterClassListController
{
    /**
     * List gadgets by score band, popularity and score.
     *
     * @return LengthAwarePaginator<int, Gadget>
     */
    public function index(): LengthAwarePaginator
    {
        return QueryBuilder::for(Gadget::class)
            ->allowedFilters([
                AllowedFilter::custom('band', new BandFilter),
                AllowedFilter::custom('popular', new DocumentedFilter),
                AllowedFilter::custom('score', new MistypedFilter),
            ])
            ->paginate(20);
    }
}
