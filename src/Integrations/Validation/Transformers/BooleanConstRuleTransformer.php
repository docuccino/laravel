<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Validation\BlankString;

/**
 * `accepted` → a boolean `const: true`, `declined` → `const: false`. The `_if` forms ask nothing of the value
 * until the other field holds one of the listed values, and no schema can state that, so they publish only a
 * note naming the condition.
 */
final class BooleanConstRuleTransformer implements RuleTransformer
{
    private const NAMES = ['accepted', 'accepted_if', 'declined', 'declined_if'];

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
        if ($rule->name === 'accepted_if' || $rule->name === 'declined_if') {
            $this->conditional($rule, $field);

            return;
        }

        $field->setType('boolean');
        $field->set('const', $rule->name === 'accepted');

        if (BlankString::refusedBy($rule->name)) {
            $field->refuseBlank();
        }
    }

    /** "Must be accepted when kind is business or charity.", from Laravel's `field,value,…` parameters. */
    private function conditional(ValidationRule $rule, ValidationField $field): void
    {
        $params = $rule->parameters;
        if (count($params) < 2) {
            return;
        }

        $verb = $rule->name === 'accepted_if' ? 'accepted' : 'declined';
        $field->note(sprintf('Must be %s when %s is %s.', $verb, $params[0], implode(' or ', array_slice($params, 1))));
    }
}
