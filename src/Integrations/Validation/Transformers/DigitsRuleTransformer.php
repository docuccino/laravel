<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * The digit-length rules `digits`, `digits_between`, `max_digits`, `min_digits` (Scribe-parity,
 * validation §4 #8/#11). A count of digits is documented honestly as a STRING with an anchored `^\d{…}$`
 * pattern — never an integer bound — because leading zeros matter (zip codes, PINs, phone fields) and
 * an `integer` schema would silently drop them. A non-numeric parameter is ignored (no pattern emitted).
 */
final class DigitsRuleTransformer implements RuleTransformer
{
    private const NAMES = ['digits', 'digits_between', 'max_digits', 'min_digits'];

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
