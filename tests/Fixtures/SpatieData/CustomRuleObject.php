<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A custom validation rule object, the kind passed to `#[Rule(new CustomRuleObject)]`. Its shape
 * cannot be recovered statically, so the reflector drops it (pinned in the tests). Never executed.
 */
final class CustomRuleObject implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void {}
}
