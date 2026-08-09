<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * Rules with no request-schema effect that are nonetheless legitimate Laravel rules: consuming them
 * here (rather than letting them fall through to the chain's unhandled path) stops each raising a
 * spurious `validation.rule-unhandled` info diagnostic. `bail` (stop on first failure), the `exclude`
 * family (drops the field from validated output — it does not describe the accepted input) and
 * `current_password` (a runtime credential check, not a shape constraint) genuinely add nothing to
 * the documented request shape. `prohibited`/`prohibits` are presence-NEGATIONS — the field (or the
 * fields it names) must be absent — so they describe no sendable shape either; the field simply
 * stays optional (its default), which is the honest documented contract for a field you must not send.
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
        'prohibited',
        'prohibits',
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
        // Intentionally nothing: the rule is recognised (so it is not diagnosed) but has no effect
        // on the documented request shape.
    }
}
