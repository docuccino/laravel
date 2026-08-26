<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * The two words Laravel's rule vocabulary has none of, both synthesised by a recovering integration and
 * never typed by anybody: `object` says the value is a JSON object, and `additional_properties` says the
 * same and states the schema its values all share — shipped as JSON on the parameter, since rule
 * parameters are strings. Either replaces the `array` a Laravel type rule can only have said: `array` is
 * the one word the vocabulary has for every array shape, so it is the vaguer statement of the same thing
 * rather than a competing one.
 */
final class AdditionalPropertiesRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['object', 'additional_properties'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        if ($rule->name === 'object') {
            $field->setType('object');

            return;
        }

        $json = $rule->parameter();
        if ($json === null) {
            return;
        }

        $value = json_decode($json, true);
        if (! is_array($value)) {
            return;
        }

        $field->setType('object');
        $field->set('additionalProperties', $value);
    }
}
