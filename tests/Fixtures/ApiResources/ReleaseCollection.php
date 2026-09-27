<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A named collection written as Laravel documents top-level metadata: Laravel's own `toArray`, the
 * collected resource found by name ({@see ReleaseResource}), and a `with()` adding `meta`.
 */
final class ReleaseCollection extends ResourceCollection
{
    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['meta' => ['key' => 'value']];
    }
}
