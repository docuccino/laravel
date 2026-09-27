<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource whose `with()` returns members on one branch and nothing on the other, and whose `data`
 * member merges into the wrapped data rather than standing beside it. Only ever reflected.
 *
 * @property object $resource
 */
final class ConditionalMetaResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

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
        if ($request->boolean('debug')) {
            return ['debug' => ['query_count' => 0], 'data' => ['traced' => true]];
        }

        return [];
    }
}
