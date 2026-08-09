<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** The unannotated sibling: nothing to read, so its field stays diagnosed as unrecoverable. */
final class OpaqueCheck implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            $fail('The :attribute is invalid.');
        }
    }
}
