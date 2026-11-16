<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Validation\ChoiceValues;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;

/**
 * `not_in` beside `array`, which the normalizer renames ({@see RuleSetNormalizer::ARRAY_NOT_IN_RULE}) and
 * nobody writes: Laravel refuses an array or object whose every element is listed, `[]` included, and
 * takes any other. So `not` holds the container's type, keeping a null the field admits out of it, and the
 * elements' list, holding every scalar Laravel compares as an entry, since nothing here types them.
 */
final class ArrayNotInRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return $rule->name === RuleSetNormalizer::ARRAY_NOT_IN_RULE;
    }

    public function handledRuleNames(): array
    {
        return [RuleSetNormalizer::ARRAY_NOT_IN_RULE];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $containers = array_values(array_intersect(['array', 'object'], $field->types()));
        $values = ChoiceValues::anyType($rule);
        if ($containers === [] || $values === []) {
            return;
        }

        $not = ['type' => count($containers) === 1 ? $containers[0] : $containers];
        if (in_array('array', $containers, true)) {
            $not['items'] = ['enum' => $values];
        }
        if (in_array('object', $containers, true)) {
            $not['additionalProperties'] = ['enum' => $values];
        }

        $field->set('not', $not);
    }
}
