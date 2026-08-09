<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use DateTimeImmutable;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * A Data class whose datetime property casts to a Unix timestamp (`format: 'U'`), so it serialises as
 * an integer rather than the default date-time string. Only ever reflected.
 */
final class TimestampData extends Data
{
    public function __construct(
        #[WithCast(DateTimeInterfaceCast::class, format: 'U')]
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
