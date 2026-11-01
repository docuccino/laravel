<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck;
use Docuccino\Laravel\Tests\Fixtures\Rules\TenantMember;
use Illuminate\Validation\ConditionalRules;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * A rule object beside a rule that reads, on each property: one that is only a check, one annotated as
 * stating nothing, and a conditional one that can make the field nullable.
 */
final class UnreadRuleData extends Data
{
    public function __construct(
        #[Max(40), Rule(new OpaqueCheck)]
        public string $token,
        #[Rule(new TenantMember)]
        public int $member,
        #[Max(200), Rule(new ConditionalRules(true, ['nullable']))]
        public string $note,
    ) {}
}
