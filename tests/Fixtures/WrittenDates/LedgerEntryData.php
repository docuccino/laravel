<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\WrittenDates;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Attributes\WithCastAndTransformer;
use Spatie\LaravelData\Attributes\WithTransformer;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Transformers\DateTimeInterfaceTransformer;

/**
 * A Data class taken and sent, one date property per way laravel-data can be told how to read or write one:
 * nothing at all, the vendor cast with one format or a list of them, the vendor transformer with and without
 * its own format or time zone, and an application's own cast and transformer.
 */
final class LedgerEntryData extends Data
{
    public function __construct(
        public CarbonImmutable $postedAt,
        public ?Carbon $clearedAt,
        public DateTimeImmutable $recordedAt,
        public DateTimeInterface $seenAt,
        #[WithCast(DateTimeInterfaceCast::class, format: 'U')]
        public CarbonImmutable $expiresAt,
        #[WithTransformer(DateTimeInterfaceTransformer::class, format: 'Y-m-d')]
        public CarbonImmutable $dueOn,
        #[WithTransformer(DateTimeInterfaceTransformer::class, 'U')]
        public CarbonImmutable $stampedAt,
        #[WithTransformer(DateTimeInterfaceTransformer::class, setTimeZone: 'Asia/Tokyo')]
        public CarbonImmutable $localAt,
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d'), WithTransformer(DateTimeInterfaceTransformer::class, format: 'Y-m-d')]
        public CarbonImmutable $bookedOn,
        #[WithCastAndTransformer(EpochTransformer::class)]
        public CarbonImmutable $syncedAt,
        #[WithTransformer(EpochTransformer::class)]
        public ?CarbonImmutable $pingedAt,
        #[WithCast(DateTimeInterfaceCast::class, format: ['Y-m-d', 'd/m/Y'])]
        public CarbonImmutable $openedOn,
        #[WithCast(EpochTransformer::class)]
        public CarbonImmutable $closedAt,
    ) {}
}
