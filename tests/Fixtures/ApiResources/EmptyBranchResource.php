<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource whose `toArray` returns `[]` on one branch.
 *
 * @property object $resource
 */
final class EmptyBranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($request->has('empty')) {
            return [];
        }

        return ['tag' => $this->resource->tag];
    }
}
