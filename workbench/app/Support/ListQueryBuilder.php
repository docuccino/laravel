<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * A user-land Spatie Query Builder SUBCLASS with a CUSTOM paginating terminal `paginateList()` —
 * mirrors Eos's own `ListQueryBuilder`. The custom terminal defers to the vendor `paginate()` one hop
 * down; the golden flagship exercises recovering it (via `integrations.query_builder.
 * pagination_terminals`) for BOTH the page parameters AND the resource-collection envelope.
 *
 * @template TModel of Model
 *
 * @extends QueryBuilder<TModel>
 */
final class ListQueryBuilder extends QueryBuilder
{
    /**
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginateList(int $perPage = 15): LengthAwarePaginator
    {
        return $this->paginate($perPage);
    }
}
