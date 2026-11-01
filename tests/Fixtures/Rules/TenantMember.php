<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Rules;

use Closure;
use Docuccino\Attributes\RuleSchema;
use Illuminate\Contracts\Validation\ValidationRule;

/** A rule with nothing a schema can state, saying so with an empty attribute. Only ever reflected. */
#[RuleSchema]
final class TenantMember implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === 0) {
            $fail('The :attribute is not a member.');
        }
    }
}
