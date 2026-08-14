<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\QueryBuilder;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Workbench\App\Models\Gadget;

/**
 * Action signatures for the seeded-root recovery: one per shape the reflection has to answer for — a
 * self-configuring builder subclass (the shape under test), the same one twice, a builder subclass with
 * no constructor of its own, and the parameters that must seed nothing at all. Only ever reflected.
 */
final class InjectedGadgetController
{
    /**
     * @return LengthAwarePaginator<int, Gadget>
     */
    public function index(GadgetListQuery $query): LengthAwarePaginator
    {
        return $query->paginateList(25);
    }

    /**
     * Two parameters of the same query type: one root, not two, so a shared constructor is walked once.
     *
     * @return LengthAwarePaginator<int, Gadget>
     */
    public function pair(GadgetListQuery $first, GadgetListQuery $second): LengthAwarePaginator
    {
        return $first->paginateList($second->paginateList(25)->total());
    }

    /**
     * @return LengthAwarePaginator<int, Gadget>
     */
    public function bare(PlainGadgetQuery $query): LengthAwarePaginator
    {
        return $query->paginateList(25);
    }

    /**
     * @return LengthAwarePaginator<int, Gadget>
     */
    public function requestOnly(Request $request, int $perPage): LengthAwarePaginator
    {
        return (new GadgetListQuery)->paginateList($perPage + $request->integer('extra'));
    }

    public function noParameters(): int
    {
        return 0;
    }
}
