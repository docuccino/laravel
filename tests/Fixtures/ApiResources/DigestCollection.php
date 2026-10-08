<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;

/**
 * A collection testing for the paginator classes Laravel dispatches on, negated: a plain list carries
 * `meta` only when it is asked for, and a page always carries the `source` its own meta is extended by.
 */
class DigestCollection extends AnonymousResourceCollection
{
    /** @return array<string, mixed> */
    public function with(Request $request): array
    {
        if (! $this->resource instanceof AbstractPaginator && ! $this->resource instanceof AbstractCursorPaginator) {
            if ($request->boolean('counted')) {
                return ['meta' => ['count' => $this->collection->count()]];
            }

            return [];
        }

        return ['meta' => ['source' => 'digest']];
    }
}
