<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A named collection of {@see ReleaseResource} whose `with()` returns a `meta` key the pagination meta also
 * sends when the collection holds a paginator.
 */
final class TalliedReleaseCollection extends ResourceCollection
{
    public $collects = ReleaseResource::class;

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['meta' => ['total' => 5, 'key' => 'value']];
    }
}
