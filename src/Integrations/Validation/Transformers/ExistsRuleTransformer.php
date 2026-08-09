<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `exists`/`unique` contribute a type and nothing else — a foreign-key reference is just an integer or
 * string identifier — so an untyped field defaults to string and a typed one is left alone. Consuming them
 * keeps them out of the unhandled-rule diagnostics.
 */
final class ExistsRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['exists', 'unique'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        if (! $field->has('type')) {
            $field->setType('string');
        }
    }
}
