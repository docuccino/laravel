<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource whose `with()` uses `mergeWhen()` as `toArray` would — which Laravel never splices there.
 *
 * @property object $resource
 */
final class MergingWithResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['tag' => $this->resource->tag];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['version' => '1', $this->mergeWhen($request->has('debug'), ['debug' => 'on'])];
    }
}
