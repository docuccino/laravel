<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;

/**
 * A resource that opts out of wrapping while its parent adds top-level members — Laravel wraps it under
 * `data` regardless, because the members need somewhere to stand beside it. Only ever reflected.
 *
 * @property object $resource
 */
final class UnwrappedEnvelopedResource extends EnvelopedResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['tag' => $this->resource->tag];
    }
}
