<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An unwrapped resource whose `with()` returns only the `data` key — Laravel still wraps it, then
 * merges that member into the data.
 *
 * @property object $resource
 */
final class MergedDataResource extends JsonResource
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
        return ['data' => ['traced' => true]];
    }
}
