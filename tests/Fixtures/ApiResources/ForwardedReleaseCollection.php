<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A named collection whose own `toArray` returns its `data` key, and whose `with()` returns one too — so
 * Laravel does not wrap it, and merges the two `data` members. (A `Collection` there would be cast to an
 * array of its protected properties by the merge, so it hands over the list.)
 */
final class ForwardedReleaseCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = ReleaseResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['data' => $this->collection->all()];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['data' => ['source' => 'ledger']];
    }
}
