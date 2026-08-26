<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\Validation\After;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * One property per rung of the date-format ladder, most specific first, plus the bare-`date` control
 * that has no date type behind it at all. Only ever reflected.
 */
final class DateLadderData extends Data
{
    public function __construct(
        // The app states the accepted wire format outright.
        #[DateFormat('d/m/Y')]
        public readonly CarbonImmutable $statedFormat,
        // The cast format is what the app really parses input with.
        #[WithCast(DateTimeInterfaceCast::class, format: 'U')]
        public readonly CarbonImmutable $castTimestamp,
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public readonly CarbonImmutable $castDateOnly,
        // A cast format no OAS `format` word describes.
        #[WithCast(DateTimeInterfaceCast::class, format: 'd/m/Y')]
        public readonly CarbonImmutable $castBespoke,
        // Nothing but the declared type.
        public readonly CarbonImmutable $declaredOnly,
        public readonly ?CarbonImmutable $nullableDeclared,
        // A comparison bound on a declared date.
        #[After('2024-01-01')]
        public readonly CarbonImmutable $afterLiteral,
        // A `date` rule with no date type behind it: nothing better is known, so `date` stands.
        #[Date]
        public readonly string $bareDateRule,
        // The reported shape: an Optional marker, a nullable union, and a `date` rule that says less
        // than the declared type does.
        #[Nullable, Date]
        public readonly Optional|CarbonImmutable|null $declaredWithDateRule = new Optional,
    ) {}
}
