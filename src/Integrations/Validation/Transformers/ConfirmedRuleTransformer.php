<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * The cross-field `confirmed` rule: documents the implicit `{field}_confirmation` partner, mirroring
 * the field's type and required flag (design §6 — cross-field rules). Runs after the type
 * transformers so the confirmation copies a resolved type.
 */
final class ConfirmedRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['confirmed'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $confirmation = $field->sibling('_confirmation');
        $confirmation->setType($field->type() ?? 'string');

        if ($field->isRequired()) {
            $confirmation->markRequired();
        }
    }
}
