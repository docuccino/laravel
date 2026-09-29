<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A collection whose `with()` returns a `meta` key the pagination meta also sends, beside one it does not.
 */
class TalliedCollection extends AnonymousResourceCollection
{
    public function with(Request $request): array
    {
        return ['meta' => ['total' => 5, 'source' => 'ledger']];
    }
}
