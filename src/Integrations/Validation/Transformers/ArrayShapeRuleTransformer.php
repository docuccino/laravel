<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * The array-shape rules (validation §4 #11): `list` → `type: array` (a Laravel `list` is a
 * sequentially-keyed array), and `distinct` → `uniqueItems: true` (its `ignore_case`/`strict`
 * parameters do not change the documented shape). `distinct` also defaults an untyped field to array,
 * since it only makes sense on one.
 */
final class ArrayShapeRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['list', 'distinct'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        if ($rule->name === 'list') {
            $field->setType('array');

            return;
        }

        if (! $field->has('type')) {
            $field->setType('array');
        }

        $field->set('uniqueItems', true);
    }
}
