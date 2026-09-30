<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TimacdonaldJsonApi;

use Illuminate\Http\Request;
use TiMacDonald\JsonApi\JsonApiResource;

/**
 * A timacdonald resource sending a flat body of its own through `resolveResourceData()`, which Laravel 12.45
 * and later send in place of the resource object on every release of the package.
 *
 * @property object $resource
 */
final class FlatTimacdonaldResource extends JsonApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return ['title' => $this->resource->title];
    }

    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function resolveResourceData($request)
    {
        return ['id' => (string) $this->resource->id, ...$this->toAttributes($request)];
    }
}
