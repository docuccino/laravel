<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `digits`, `digits_between`, `max_digits`, `min_digits` → a string with an anchored `^\d{…}$` pattern,
 * never an integer bound: leading zeros matter for zip codes, PINs and phone numbers, and an `integer`
 * schema would silently drop them. A non-numeric parameter emits no pattern.
 */
final class DigitsRuleTransformer implements RuleTransformer
{
    private const NAMES = ['digits', 'digits_between', 'max_digits', 'min_digits'];

    /** The example run is a prefix of this, repeated — so a 12-digit field reads 123456789012. */
    private const DIGITS = '1234567890';

    /** Past this a conforming run is noise rather than an illustration. */
    private const MAX_RUN = 64;

    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, self::NAMES, true);
    }

    public function handledRuleNames(): array
    {
        return self::NAMES;
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $quantifier = $this->quantifier($rule);
        if ($quantifier === null) {
            return;
        }

        if (! $field->has('type')) {
            $field->setType('string');
        }

        $field->set('pattern', '^\d'.$quantifier.'$');

        // A digit run of the shortest legal length: the letters the generic synthesis reaches for are
        // exactly what this pattern refuses, and leading zeros are why this is a string at all.
        $length = $this->shortestRun($rule);
        if ($length <= self::MAX_RUN) {
            $field->proposeExample(str_pad('', $length, self::DIGITS));
        }
    }

    /** The shortest run the quantifier admits — what {@see quantifier()} puts left of the comma. */
    private function shortestRun(ValidationRule $rule): int
    {
        return $rule->name === 'max_digits' ? 1 : max(1, (int) $rule->parameter(0));
    }

    private function quantifier(ValidationRule $rule): ?string
    {
        $first = $rule->parameter(0);
        $second = $rule->parameter(1);

        return match ($rule->name) {
            'digits' => ctype_digit((string) $first) ? '{'.$first.'}' : null,
            'min_digits' => ctype_digit((string) $first) ? '{'.$first.',}' : null,
            'max_digits' => ctype_digit((string) $first) ? '{1,'.$first.'}' : null,
            default => ctype_digit((string) $first) && ctype_digit((string) $second) ? '{'.$first.','.$second.'}' : null,
        };
    }
}
