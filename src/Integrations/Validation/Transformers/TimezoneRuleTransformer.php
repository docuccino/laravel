<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `timezone`. There's no JSON-Schema format for a timezone identifier, so it's a string plus a description.
 * Untyped fields default to string; an existing description is preserved.
 */
final class TimezoneRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return $rule->name === 'timezone';
    }

    public function handledRuleNames(): array
    {
        return ['timezone'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        if (! $field->has('type')) {
            $field->setType('string');
        }

        if (! $field->has('description')) {
            $field->set('description', 'Must be a valid timezone identifier.');
        }

        // No keyword carries "is a timezone", so the example is the rule's to propose.
        $field->proposeExample('UTC');
    }
}
