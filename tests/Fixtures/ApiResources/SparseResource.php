<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource whose every `toArray` key is conditional, so all of them can be filtered out.
 *
 * @property object $resource
 */
final class SparseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['tag' => $this->when($request->has('tag'), fn () => $this->resource->tag)];
    }
}
