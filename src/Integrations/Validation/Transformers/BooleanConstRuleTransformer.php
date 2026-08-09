<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `accepted` → a boolean `const: true`, `declined` → `const: false`. The `_if` variants keep the same
 * `const` — the documented happy path — and add a description naming the condition that enforces it.
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
        $accepted = $rule->name === 'accepted' || $rule->name === 'accepted_if';

        $field->setType('boolean');
        $field->set('const', $accepted);

        if ($rule->name === 'accepted_if' || $rule->name === 'declined_if') {
            $condition = $this->condition($rule);
            if ($condition !== null) {
                $verb = $accepted ? 'accepted' : 'declined';
                $note = sprintf('Must be %s when %s.', $verb, $condition);
                $existing = $field->get('description');
                $field->set('description', is_string($existing) && $existing !== '' ? $existing.' '.$note : $note);
            }
        }
    }

    /** "field is value" (or several such clauses joined by "or") from the field,value parameter pairs. */
    private function condition(ValidationRule $rule): ?string
    {
        $params = $rule->parameters;
        if (count($params) < 2) {
            return null;
        }

        $clauses = [];
        for ($i = 0; $i + 1 < count($params); $i += 2) {
            $clauses[] = sprintf('%s is %s', $params[$i], $params[$i + 1]);
        }

        return implode(' or ', $clauses);
    }
}
