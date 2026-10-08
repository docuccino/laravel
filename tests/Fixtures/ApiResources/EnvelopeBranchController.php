<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Lists of collections whose `with()` branches on the paginator they may wrap: a plain list, a page, a page
 * an application's own terminal builds, and a page built by hand, which no paginating terminal names.
 */
final class EnvelopeBranchController
{
    public function gazette(Collection $users): AnonymousResourceCollection
    {
        return GazetteResource::collection($users);
    }

    public function gazettePages(): AnonymousResourceCollection
    {
        return GazetteResource::collection([]);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     */
    public function gazetteBuilt(LengthAwarePaginator $page): AnonymousResourceCollection
    {
        return GazetteResource::collection($page);
    }

    public function digest(Collection $users): AnonymousResourceCollection
    {
        return DigestResource::collection($users);
    }

    public function digestPages(): AnonymousResourceCollection
    {
        return DigestResource::collection([]);
    }

    public function listed(Collection $users): AnonymousResourceCollection
    {
        return CatalogueResource::collection($users);
    }

    public function gazetteListed(): AnonymousResourceCollection
    {
        return GazetteResource::collection([]);
    }
}
