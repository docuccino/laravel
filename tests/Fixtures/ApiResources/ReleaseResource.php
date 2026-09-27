<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;

/**
 * A resource inheriting its top-level members from {@see EnvelopedResource}.
 *
 * @property object $resource
 */
final class ReleaseResource extends EnvelopedResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['tag' => $this->resource->tag];
    }
}
