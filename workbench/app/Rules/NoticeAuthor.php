<?php

declare(strict_types=1);

namespace Workbench\App\Rules;

use Closure;
use Docuccino\Attributes\RuleSchema;
use Illuminate\Contracts\Validation\ValidationRule;

/** A custom rule saying, with an empty #[RuleSchema], that it states nothing a schema can. Only ever reflected. */
#[RuleSchema]
final class NoticeAuthor implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === 0) {
            $fail('The :attribute is not a member of this team.');
        }
    }
}
