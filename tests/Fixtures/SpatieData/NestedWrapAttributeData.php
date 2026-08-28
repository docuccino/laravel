<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/** The item class named by an attribute, with no docblock generic to recover. */
final class NestedWrapAttributeData extends Data
{
    public function __construct(
        #[DataCollectionOf(NestedWrapItemData::class)]
        public DataCollection $things,
    ) {}
}
