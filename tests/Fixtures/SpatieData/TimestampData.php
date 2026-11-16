<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use DateTimeImmutable;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * A Data class whose datetime property casts from a Unix timestamp (`format: 'U'`), which shapes what a
 * request sends and not what a response carries. Only ever reflected.
 */
final class TimestampData extends Data
{
    public function __construct(
        #[WithCast(DateTimeInterfaceCast::class, format: 'U')]
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
