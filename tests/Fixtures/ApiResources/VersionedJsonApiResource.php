<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * A first-party JSON:API resource declaring its own `$jsonApiInformation`, which Laravel reads for the
 * resource and not for its collection — that reads the base class's.
 *
 * @property object $resource
 */
final class VersionedJsonApiResource extends JsonApiResource
{
    /** @var array{version?: string, ext?: array<mixed>, profile?: array<mixed>, meta?: array<mixed>} */
    public static $jsonApiInformation = ['version' => '1.0'];

    public function toId(Request $request): string
    {
        return (string) $this->resource->id;
    }

    public function toType(Request $request): string
    {
        return 'versioned';
    }
}
