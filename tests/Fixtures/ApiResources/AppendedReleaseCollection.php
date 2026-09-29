<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A named collection of {@see ReleaseResource} whose `with()` returns a `data` key, which Laravel merges
 * into the list it sends there.
 */
final class AppendedReleaseCollection extends ResourceCollection
{
    public $collects = ReleaseResource::class;

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['data' => ['source' => 'ledger']];
    }
}
