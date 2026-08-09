<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * Pins the honest degradation of an OBJECT-valued `#[Rule(new …)]` escape hatch: only string rule
 * arguments are recovered, so a rule OBJECT (a custom Rule instance) is silently dropped — the field
 * keeps its type-based schema rather than a garbage token. A sibling string `#[Rule('max:5')]` proves
 * the recoverable form still lands. Only ever reflected.
 */
final class PinnedRuleData extends Data
{
    public function __construct(
        #[Rule(new CustomRuleObject)]
        public string $label,
        #[Rule('max:5')]
        public string $code,
    ) {}
}
