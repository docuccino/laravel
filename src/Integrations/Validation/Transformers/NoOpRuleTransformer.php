<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * Legitimate Laravel rules that say nothing about the request shape. Consuming them here keeps each from
 * raising a spurious `validation.rule-unhandled` diagnostic. `bail` is about failure handling; the
 * `exclude` family drops the field from validated output, not from the accepted input;
 * `current_password` is a runtime credential check. The prohibition family is NOT here — a field that
 * must not be sent is a real fact about the request, handled by {@see ProhibitedRuleTransformer}.
 */
final class NoOpRuleTransformer implements RuleTransformer
{
    private const NAMES = [
        'bail',
        'exclude',
        'exclude_if',
        'exclude_unless',
        'exclude_with',
        'exclude_without',
        'current_password',
    ];

    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return self::NAMES;
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        // Nothing to do — recognising the rule is the whole point.
    }
}
