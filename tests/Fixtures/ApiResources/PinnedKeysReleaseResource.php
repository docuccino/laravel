<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Attributes\PreserveKeys;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource whose `collection()` keeps the keys of what it is given by `#[PreserveKeys]` — an
 * attribute only a framework that reads it honours.
 *
 * @property object $resource
 */
#[PreserveKeys]
final class PinnedKeysReleaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['tag' => $this->resource->tag];
    }
}
