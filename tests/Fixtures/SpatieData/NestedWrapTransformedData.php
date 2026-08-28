<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\WithTransformer;
use Spatie\LaravelData\Data;

/** The same collection, unwrapped by a transformer — opaque, so the diagnostic must stay silent. */
final class NestedWrapTransformedData extends Data
{
    /** @param list<NestedWrapItemData> $things */
    public function __construct(
        #[WithTransformer(UnwrapCollectionTransformer::class)]
        public array $things,
    ) {}
}
