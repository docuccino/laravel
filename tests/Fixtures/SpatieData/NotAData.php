<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\Request;

/**
 * NOT a spatie Data class — a plain class that nonetheless declares `calculateResponseStatus()` in its
 * own file (so it would pass the override's file-identity check). `DataResponseStatus` must decline it
 * on the `isData()` guard alone, returning no statuses AND raising no diagnostic: the spatie tier has
 * nothing to say about a class that is not spatie's. Only ever reflected.
 */
final class NotAData
{
    public function __construct(
        public string $id,
    ) {}

    protected function calculateResponseStatus(Request $request): int
    {
        return 201;
    }
}
