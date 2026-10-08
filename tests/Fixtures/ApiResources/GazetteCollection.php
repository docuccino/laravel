<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A collection whose `with()` adds `meta` to a plain list only: Laravel merges `with()` into a page's own
 * `meta`, so the paginator branch leaves it to the framework.
 */
class GazetteCollection extends AnonymousResourceCollection
{
    /** @return array<string, mixed> */
    public function with(Request $request): array
    {
        if ($this->resource instanceof Paginator || $this->resource instanceof CursorPaginator) {
            return parent::with($request);
        }

        return ['meta' => (object) []];
    }
}
