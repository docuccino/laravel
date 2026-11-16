<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Validation\Rules\In as InRule;
use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\References\RouteParameterReference;

/**
 * A choice object holding a value no list can, an `#[Enum]` naming its class through a reference that
 * cannot be read as one, and an `#[In]` given a value only a request resolves.
 */
final class UnreadChoiceObjectData extends Data
{
    public function __construct(
        #[Max(20), Rule([new InRule([['nested']])])]
        public string $shade,
        #[Enum(new RouteParameterReference('kind'))]
        public string $kind,
        #[In('red', new RouteParameterReference('palette'))]
        public string $tint,
    ) {}
}
