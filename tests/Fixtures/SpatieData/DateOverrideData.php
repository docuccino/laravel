<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Three date-typed properties for a `rules()` override to replace: one restating the bare `date` word,
 * one stating the wire format outright, one naming no type at all. Only ever reflected.
 */
final class DateOverrideData extends Data
{
    public function __construct(
        public readonly CarbonImmutable $restatedDate,
        public readonly CarbonImmutable $statedFormat,
        public readonly CarbonImmutable $noTypeStated,
    ) {}
}
