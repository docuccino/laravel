<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * The two paginated collections spatie/laravel-data serialises, returned the way its own docs show —
 * `collect()` handed a paginator and the collection class. What the analyser answers for each is
 * scripted; the return types here are the ones a real application writes.
 */
final class PaginatedCollectionController
{
    /** @return PaginatedDataCollection<int, AuthorData> */
    public function index(): PaginatedDataCollection
    {
        /** @var PaginatedDataCollection<int, AuthorData> $collection */
        $collection = AuthorData::collect(DB::table('authors')->paginate(), PaginatedDataCollection::class);

        return $collection;
    }

    /** @return CursorPaginatedDataCollection<int, AuthorData> */
    public function feed(): CursorPaginatedDataCollection
    {
        /** @var CursorPaginatedDataCollection<int, AuthorData> $collection */
        $collection = AuthorData::collect(DB::table('authors')->cursorPaginate(), CursorPaginatedDataCollection::class);

        return $collection;
    }
}
