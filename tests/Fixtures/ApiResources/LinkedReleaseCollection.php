<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A named collection whose own `toArray` already returns its `data` key beside `links`, as Laravel's
 * documentation writes one — so Laravel does not wrap it again.
 */
final class LinkedReleaseCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = ReleaseResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'links' => ['self' => 'link-value'],
        ];
    }
}
