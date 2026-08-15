<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;

/**
 * An in-process twin of the fixture app's `App\Data\SaveAnswersData`, carrying the same validation
 * attributes so the rule set built over the real engine's recovered types is the one a real request
 * would get. `answers` states `#[ArrayType]` itself; `touched_fields` states nothing, so its rule comes
 * from its type. Only ever reflected.
 */
final class SaveAnswersData extends Data
{
    public function __construct(
        #[Required, StringType]
        public readonly string $zone_key,
        #[Nullable, ArrayType]
        public readonly ?array $answers = null,
        public readonly array $touched_fields = [],
    ) {}
}
