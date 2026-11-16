<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\Validation\GreaterThan;
use Spatie\LaravelData\Attributes\Validation\LessThan;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\References\FieldReference;

/**
 * The range {@see NestedReferenceData} nests: one bound named relative to this object, and one named from the
 * root of the request.
 */
final class NestedReferenceRangeData extends Data
{
    public function __construct(
        public int $min,
        #[GreaterThan('min')]
        public int $max,
        #[LessThan(new FieldReference('max', fromRoot: true))]
        public int $cap,
    ) {}
}
