<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `timezone` (Scribe-parity, validation §4 #8): a timezone identifier has no JSON-Schema format, so it
 * is documented as a string with a human description. Defaults an untyped field to string; an existing
 * description is preserved.
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
    }
}
