<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource keeping Laravel's `with()` but setting the `$with` property it returns.
 *
 * @property object $resource
 */
final class WithPropertyResource extends JsonResource
{
    /** @var array<string, mixed> */
    public $with = ['meta' => ['version' => 1]];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['tag' => $this->resource->tag];
    }
}
