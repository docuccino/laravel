<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DescribedNotes;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\QueryBuilder;
use Workbench\App\Models\Gadget;

/** A list whose sorts, includes and sparse fieldsets are allow-listed, described by its author and not. */
final class DescribedListController
{
    /**
     * @return LengthAwarePaginator<int, Gadget>
     */
    #[QueryParameter('sort', description: 'How to order the list.')]
    #[QueryParameter('include', description: 'Related records to embed.')]
    #[QueryParameter('fields', description: 'The gadget columns to return.')]
    #[QueryParameter('fields[beacon]', description: 'The beacon columns to return.')]
    public function index(): LengthAwarePaginator
    {
        return QueryBuilder::for(Gadget::class)
            ->allowedSorts(['starts_at', 'score'])
            ->defaultSort('-starts_at')
            ->allowedIncludes(['beacon'])
            ->allowedFields(['id', 'score', 'beacon.id'])
            ->paginate(20);
    }

    /**
     * @return LengthAwarePaginator<int, Gadget>
     */
    public function browse(): LengthAwarePaginator
    {
        return QueryBuilder::for(Gadget::class)
            ->allowedSorts(['starts_at', 'score'])
            ->defaultSort('-starts_at')
            ->allowedIncludes(['beacon'])
            ->allowedFields(['id', 'score', 'beacon.id'])
            ->paginate(20);
    }
}
