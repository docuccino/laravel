<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Rules;

use Closure;
use Docuccino\Attributes\RuleSchema;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An idiomatic custom rule documented by its class-level `#[RuleSchema]` — the shape the recovery is
 * meant to read. Its constructor argument is deliberately there: it must be ignored, not folded.
 */
#[RuleSchema(
    type: 'string',
    format: 'bank-reference',
    pattern: '[A-Z]{2}[0-9]{6}',
    min: 8,
    max: 8,
    description: 'A bank reference: two country letters then six digits.',
    example: 'GB123456',
)]
final readonly class BankReference implements ValidationRule
{
    public function __construct(private string $country = 'GB') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! str_starts_with($value, $this->country)) {
            $fail('The :attribute is not a valid bank reference.');
        }
    }
}
