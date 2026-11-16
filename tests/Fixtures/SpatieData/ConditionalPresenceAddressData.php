<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/** The address {@see ConditionalPresenceData} nests under conditional presence rules. */
final class ConditionalPresenceAddressData extends Data
{
    public function __construct(
        public string $line,
    ) {}
}
