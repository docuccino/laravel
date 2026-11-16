<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\GreaterThan;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * A field reference written at the root beside the same range nested as one Data object and as a list of
 * them. laravel-data resolves a reference under the path of the object that declares it.
 */
final class NestedReferenceData extends Data
{
    /**
     * @param  list<NestedReferenceRangeData>|Optional  $ranges
     */
    public function __construct(
        public int $min,
        #[GreaterThan('min')]
        public int $max,
        public NestedReferenceRangeData $range,
        #[DataCollectionOf(NestedReferenceRangeData::class)]
        public array|Optional $ranges,
    ) {}
}
