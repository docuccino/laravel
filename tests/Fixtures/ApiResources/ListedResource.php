<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A base resource sharing {@see ListedCollection} across its family: `collection()` calls
 * `static::newCollection()`, so every member's `::collection()` builds one.
 */
abstract class ListedResource extends JsonResource
{
    /**
     * @param  mixed  $resource
     */
    protected static function newCollection($resource): ListedCollection
    {
        return new ListedCollection($resource, static::class);
    }
}
