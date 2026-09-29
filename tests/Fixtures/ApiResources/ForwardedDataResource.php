<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource whose `with()` returns a `data` key holding whatever keys the request names, one of which
 * may be one of `toArray`'s own.
 *
 * @property object $resource
 */
final class ForwardedDataResource extends JsonResource
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
        /** @var array<string, mixed> $extra */
        $extra = (array) $request->query('extra', []);

        return ['data' => $extra];
    }
}
