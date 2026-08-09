<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Rules;

use Docuccino\Attributes\RuleSchema;

/**
 * Stands in for a rule shipped by a package: it implements no Laravel interface at all, yet documents
 * because the attribute — not the interface — is the contract.
 */
#[RuleSchema(type: 'string', enum: ['GBP', 'EUR', 'USD'], description: 'A supported settlement currency.')]
final class VendorCurrencyRule
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function passes(string $attribute, mixed $value, array $data = []): bool
    {
        return is_string($value) && $value !== '';
    }
}
