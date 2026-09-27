<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * A first-party JSON:API resource whose `toMeta` uses `when()`, which Laravel does not filter there.
 *
 * @property object $resource
 */
final class MeteredJsonApiResource extends JsonApiResource
{
    public function toId(Request $request): string
    {
        return (string) $this->resource->id;
    }

    public function toType(Request $request): string
    {
        return 'metered';
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return ['title' => $this->resource->title];
    }

    /**
     * @return array<string, mixed>
     */
    public function toMeta(Request $request): array
    {
        return ['cached' => $this->when($request->has('cached'), true)];
    }
}
