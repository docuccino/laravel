<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Validation\ChoiceValues;

/**
 * `not_in:a,b,c` → `not: {enum: […]}` — the mirror of {@see ChoiceRuleTransformer}'s `in`, excluding a
 * value set instead of restricting to one. Also covers spatie's `#[NotIn]`, which recovers the same rule.
 *
 * Unlike `in`, it never types the field: every value outside the set passes, whatever its type, so a type
 * read from the set would refuse requests the server takes. The type is the other rules' to state.
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
        // Read in the type the other rules declare, as `in` is; an entry no value of that type equals
        // excludes nothing.
        $values = ChoiceValues::of($rule, $field->types());
        if ($values === []) {
            return;
        }

        $field->set('not', ['enum' => $values]);
    }
}
