<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * A Data class that strips the envelope from a NESTED paginated collection while its own root stays
 * wrapped — spatie puts `withoutWrapping()` on collections and on the transformation-context factory too,
 * so the receiver is the only thing that tells "this class is unwrapped" from "something inside it is".
 * Only ever reflected.
 */
final class NestedUnwrappedData extends Data
{
    /**
     * @param  PaginatedDataCollection<int, AuthorData>  $authors
     */
    public function __construct(
        public int $id,
        public PaginatedDataCollection $authors,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function bareAuthors(): array
    {
        return $this->authors->withoutWrapping()->toArray();
    }
}
