<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The collection {@see ListedResource} hands every `::collection()` of its family to: a list carrying a
 * `meta` object and the API version beside its `data`.
 */
class ListedCollection extends AnonymousResourceCollection
{
    public function with(Request $request): array
    {
        return ['meta' => ['listed_at' => 'now'], 'api_version' => '2'];
    }
}
