<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\QueryBuilder;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Workbench\App\Models\Gadget;

/**
 * A query object that IS the builder and configures itself in its own CONSTRUCTOR — the shape an action
 * is HANDED by the container, where nothing in the action body leads to the allow-lists. Only its
 * SIGNATURE is ever reflected (the trace over it is scripted); never queried.
 *
 * @extends QueryBuilder<Gadget>
 */
final class GadgetListQuery extends QueryBuilder
{
    public function __construct()
    {
        parent::__construct(Gadget::query());

        $this->allowedFilters([AllowedFilter::exact('status')])
            ->allowedSorts(['score'])
            ->defaultSort('score');
    }

    /**
     * The custom paginating terminal, one hop above the vendor one.
     *
     * @return LengthAwarePaginator<int, Gadget>
     */
    public function paginateList(int $perPage = 15): LengthAwarePaginator
    {
        return $this->paginate($perPage);
    }
}
