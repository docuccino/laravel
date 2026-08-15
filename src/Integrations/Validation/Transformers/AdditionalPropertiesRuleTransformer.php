<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `additional_properties` — a recovered `array<string, V>` property: a JSON *object* whose values all share
 * a schema, which Laravel's vocabulary has no word for. The recovering integration states that value schema
 * outright and ships it as JSON on the parameter, since rule parameters are strings. `type: object` is set
 * unconditionally, the rule only ever coming from a recovered map type.
 */
final class AdditionalPropertiesRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['additional_properties'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
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
