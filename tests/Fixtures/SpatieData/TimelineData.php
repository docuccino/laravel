<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * The in-process twin of the fixture app's `App\Data\TimelineData`: nullable timestamps, one of them
 * carrying the `format: 'U'` cast, and a non-null control. Only ever reflected — the mapper's guards
 * reflect the FQCN they are handed, so the cast attribute has to be readable here.
 */
final class TimelineData extends Data
{
    public function __construct(
        public readonly ?CarbonImmutable $publishedAt,
        #[WithCast(DateTimeInterfaceCast::class, format: 'U')]
        public readonly ?CarbonImmutable $expiresAt,
        public readonly CarbonImmutable $createdAt,
    ) {}
}
