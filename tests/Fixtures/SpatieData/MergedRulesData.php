<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\MergeValidationRules;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * The in-process twin of the fixture app's `App\Data\MergedRulesData` — `#[MergeValidationRules]` is a
 * class attribute, so it has to sit on the FQCN the reflector is handed. Only ever reflected.
 */
#[MergeValidationRules]
final class MergedRulesData extends Data
{
    public function __construct(
        #[Max(255)]
        public readonly string $name,
    ) {}
}
