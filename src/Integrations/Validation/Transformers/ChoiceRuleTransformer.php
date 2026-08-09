<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `in:a,b,c` and `enum` (a folded `Rule::enum(…)`/`Rule::in(…)`) → an `enum` of the allowed values.
 * Numeric-only sets get an integer type, everything else string. A {@see ValidationRule::$note} — an enum
 * FQCN — becomes the description.
 */
final class ChoiceRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['in', 'enum'];
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

        $field->set('enum', $allNumeric ? array_map('intval', $values) : $values);

        if ($rule->note !== null && ! $field->has('description')) {
            $field->set('description', $rule->note);
        }
    }
}
