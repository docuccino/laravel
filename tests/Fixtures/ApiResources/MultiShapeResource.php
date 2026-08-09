<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource fixture whose `toArray` has request-dependent branches (several return sites) and a
 * nested object with a conditional field. Only ever reflected — the real return shapes are supplied
 * by the stub engine in tests (Wave C items 6 + 7).
 *
 * @property object $resource
 */
final class MultiShapeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($request->boolean('summary')) {
            return [
                'id' => $this->resource->id,
                'name' => $this->resource->name,
                'status' => 'draft',
                'meta' => ['count' => $this->resource->count],
            ];
        }

        return [
            'id' => $this->resource->id,
            'status' => $this->resource->status,
            'extra' => $this->resource->extra,
            'meta' => ['count' => $this->resource->count, 'tag' => $this->when(true, 'x')],
        ];
    }
}
