<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lists of a resource whose family overrides `newCollection()`, unpaginated and paginated, beside a list
 * of a resource keeping the framework's, and a paginated list whose collection's `with()` collides with the page.
 */
final class ListedCollectionController
{
    public function index(): AnonymousResourceCollection
    {
        return CatalogueResource::collection([]);
    }

    public function paginated(): AnonymousResourceCollection
    {
        return CatalogueResource::collection([]);
    }

    public function plain(): AnonymousResourceCollection
    {
        return ReleaseResource::collection([]);
    }

    public function tallied(): AnonymousResourceCollection
    {
        return CatalogueResource::collection([]);
    }
}
