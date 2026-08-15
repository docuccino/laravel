<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Docuccino\Attributes\Hidden as DocuccinoHidden;
use Docuccino\Attributes\HiddenFromRequest;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;

/**
 * The value class of a `array<string, TeamMemberData>` map. Every request/response asymmetry a Data
 * class has is on it at once: a directionally mapped key, a property the request must not carry, and a
 * property the response must not carry (which is still sendable). Only ever reflected.
 */
final class TeamMemberData extends Data
{
    public function __construct(
        #[MapInputName('email_address'), MapOutputName('email')]
        public readonly string $email,
        #[HiddenFromRequest]
        public readonly string $internal_risk_score,
        #[DocuccinoHidden]
        public readonly string $joining_token,
    ) {}
}
