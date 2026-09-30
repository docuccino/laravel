<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource whose body carries a `data` key of its own and which forces its wrap anyway.
 *
 * @property object $resource
 */
final class ForceWrappedResource extends JsonResource
{
    public static bool $forceWrapping = true;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['data' => ['source' => 'ledger'], 'tag' => $this->resource->tag];
    }
}
