<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\QueryBuilder;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Workbench\App\Models\Gadget;

/**
 * Enum filters that split their values on a separator of their own, set with `->delimiter()`, beside one
 * splitting on the configured comma: a pipe, no splitting at all, a pipe written before and after a
 * default, and the comma named outright.
 */
final class FilterDelimitersController
{
    /**
     * @return LengthAwarePaginator<int, Gadget>
     */
    public function index(): LengthAwarePaginator
    {
        return QueryBuilder::for(Gadget::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('piped', 'status')->delimiter('|'),
                AllowedFilter::exact('unsplit', 'status')->delimiter(''),
                AllowedFilter::exact('piped_default', 'status')->delimiter('|')->default('draft|published'),
                AllowedFilter::exact('default_then_piped', 'status')->default('draft')->delimiter('|'),
                AllowedFilter::exact('commas', 'status')->delimiter(','),
            ])
            ->paginate(20);
    }
}
