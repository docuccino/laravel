<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lists of a resource that keeps the keys of what it is given, unpaginated and paginated, beside a
 * paginated list of one that does not, and a resource that forces its wrap.
 */
final class KeyedCollectionController
{
    public function index(): AnonymousResourceCollection
    {
        return KeyedReleaseResource::collection([]);
    }

    public function paginated(): AnonymousResourceCollection
    {
        return KeyedReleaseResource::collection([]);
    }

    public function renumbered(): AnonymousResourceCollection
    {
        return ReleaseResource::collection([]);
    }

    public function forced(): ForceWrappedResource
    {
        return new ForceWrappedResource(null);
    }
}
