<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;

/**
 * The words Laravel's rule vocabulary has none of, all synthesised by a recovering integration and never
 * typed by anybody. `array` is the one word the vocabulary has for every array shape, so each of these is
 * a sharper reading of it rather than a competing one, and replaces it:
 *
 * - `object` says the value is a JSON object;
 * - `additional_properties` says the same and states the schema its values all share — shipped as JSON on
 *   the parameter, since rule parameters are strings;
 * - `array_or_object` says the opposite, that nothing decided ({@see RuleSetNormalizer}) — an `array` rule
 *   with no item and no key rules under it, which a JSON array and a JSON object both satisfy. It
 *   publishes both types: a consumer is under-served by the union and misled by whichever half we would
 *   otherwise have picked.
 */
final class AdditionalPropertiesRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['object', 'additional_properties', RuleSetNormalizer::UNDECIDED_RULE];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        if ($rule->name === RuleSetNormalizer::UNDECIDED_RULE) {
            $field->setTypes(['array', 'object']);

            return;
        }

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
