<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;

/**
 * Valid Laravel rules with nothing left to say about the request shape by the time the chain runs, consumed
 * so none raises `validation.rule-unhandled`. The exclude family's effect is on rule ORDER, which only
 * {@see RuleSetNormalizer} sees, and it has already applied it; prohibitions are a real fact about the
 * request, handled by {@see ProhibitedRuleTransformer}.
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
