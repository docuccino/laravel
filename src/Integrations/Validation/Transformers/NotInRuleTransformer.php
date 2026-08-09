<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `not_in:a,b,c` → `not: {enum: […]}` (validation §4 #8/#11): the mirror of {@see ChoiceRuleTransformer}'s
 * `in`, excluding a value set rather than restricting to one. A numeric-only set infers an integer type;
 * otherwise string. Also unblocks spatie's `#[NotIn]` attribute, which recovered a `not_in` token that
 * previously dead-ended. Defaults an untyped field; an explicit type is preserved.
 */
final class NotInRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return $rule->name === 'not_in';
    }

    public function handledRuleNames(): array
    {
        return ['not_in'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $values = $rule->parameters;
        if ($values === []) {
            return;
        }

        $allNumeric = true;
        foreach ($values as $value) {
            if (! is_numeric($value) || str_contains($value, '.')) {
                $allNumeric = false;
                break;
            }
        }

        if (! $field->has('type')) {
            $field->setType($allNumeric ? 'integer' : 'string');
        }

        $field->set('not', ['enum' => $allNumeric ? array_map('intval', $values) : $values]);
    }
}
