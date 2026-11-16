<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\WrittenDates;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * Dates read by whichever cast laravel-data finds for each: one class with a `data.casts` entry of its own,
 * one met only through an interface, and two declared as the interface, which the vendor cast cannot build
 * unless it is told the class to.
 */
final class CastOrderData extends Data
{
    public function __construct(
        public CarbonImmutable $immutableAt,
        public DateTimeImmutable $plainAt,
        public DateTimeInterface $anyAt,
        #[WithCast(DateTimeInterfaceCast::class, type: CarbonImmutable::class)]
        public DateTimeInterface $builtAt,
    ) {}
}
