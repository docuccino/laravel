<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Docuccino\Laravel\Integrations\ApiResources\JsonResourceSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A nested resource the {@see JsonResourceSchema} mapper
 * hoists as its own component when an outer resource references it. Only ever reflected.
 *
 * @property object $resource
 */
final class AuthorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource->name,
            'email' => $this->resource->email,
        ];
    }
}
