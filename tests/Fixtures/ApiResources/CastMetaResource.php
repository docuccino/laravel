<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource building its objects with `(object)` casts, in its body and in `with()`.
 *
 * @property object $resource
 */
final class CastMetaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['tag' => $this->resource->tag, 'settings' => (object) []];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['meta' => (object) [], 'links' => (object) ['self' => $request->url()]];
    }
}
