<?php

declare(strict_types=1);

namespace Workbench\App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A custom rule with no #[RuleSchema], so the build can see it and not read it. Only ever reflected. */
final class NoticeFilter implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && str_contains($value, '<')) {
            $fail('The :attribute may not contain markup.');
        }
    }
}
