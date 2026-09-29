<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A collection whose `with()` returns a `data` key, which Laravel merges into the list it sends there.
 */
class AppendedCollection extends AnonymousResourceCollection
{
    public function with(Request $request): array
    {
        return ['data' => ['source' => 'ledger']];
    }
}
