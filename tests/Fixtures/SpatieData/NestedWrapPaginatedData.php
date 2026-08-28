<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * A PAGINATED nested collection. Spatie always wraps one, and the envelope it wraps in carries `meta`
 * and `links` — which the schema already publishes — so this is not a nested-wrap question.
 *
 * @property PaginatedDataCollection<int, NestedWrapItemData> $things
 */
final class NestedWrapPaginatedData extends Data
{
    /** @param PaginatedDataCollection<int, NestedWrapItemData> $things */
    public function __construct(
        #[DataCollectionOf(NestedWrapItemData::class)]
        public PaginatedDataCollection $things,
    ) {}
}
