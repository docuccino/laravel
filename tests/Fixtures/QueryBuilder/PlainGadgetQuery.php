<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\QueryBuilder;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\QueryBuilder;
use Workbench\App\Models\Gadget;

/**
 * A builder subclass that adds no constructor of its own — it is handed its subject like the package's
 * own builder — so there is nothing of the application's to trace and it seeds no root.
 *
 * @extends QueryBuilder<Gadget>
 */
final class PlainGadgetQuery extends QueryBuilder
{
    /**
     * @return LengthAwarePaginator<int, Gadget>
     */
    public function paginateList(int $perPage = 15): LengthAwarePaginator
    {
        return $this->paginate($perPage);
    }
}
