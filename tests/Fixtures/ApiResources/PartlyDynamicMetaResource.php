<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource whose `with()` has a shape on one branch and a runtime-built array on the other, and whose
 * `toArray` falls back to Laravel's on one branch.
 *
 * @property object $resource
 */
final class PartlyDynamicMetaResource extends JsonResource
{
    /** @var array<string, mixed> */
    public array $extraMeta = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($request->boolean('raw')) {
            return (array) parent::toArray($request);
        }

        return ['tag' => $this->resource->tag];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        if ($request->has('debug')) {
            return ['meta' => ['query_count' => 0]];
        }

        return $this->extraMeta;
    }
}
