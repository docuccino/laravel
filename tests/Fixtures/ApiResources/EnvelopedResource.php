<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A base resource adding top-level members to every response through `with()`, which Laravel calls for
 * the response-root resource only.
 */
abstract class EnvelopedResource extends JsonResource
{
    /**
     * @return array{meta: object, version: string}
     */
    public function with(Request $request): array
    {
        return ['meta' => (object) [], 'version' => '1'];
    }
}
