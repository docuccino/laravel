<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * A first-party JSON:API resource whose `toLinks` returns the link as a URL string, as Laravel's own
 * documentation writes one, and whose `author` relationship a request can ask to include.
 *
 * @property Model $resource
 */
final class LinkedJsonApiResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'linked';
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return ['title' => $this->resource->getAttribute('title')];
    }

    /**
     * @return array<int, string>
     */
    public function toRelationships(Request $request): array
    {
        return ['author'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toLinks(Request $request): array
    {
        return ['self' => '/linked/'.$this->resource->getKey()];
    }
}
