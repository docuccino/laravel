<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Docuccino\Attributes\Mock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource whose published keys come from `toArray` rather than from properties, so the class-level
 * `#[Mock]` form is the only one that can name one. `absent` names a key the shape has not got. Only
 * ever reflected.
 *
 * @property object $resource
 */
#[Mock(faker: 'safeEmail', property: 'email')]
#[Mock(seedGroup: 'persona', property: 'handle')]
#[Mock(faker: 'word', property: 'absent')]
final class PersonaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'handle' => $this->resource->handle,
            'email' => $this->resource->email,
        ];
    }
}
