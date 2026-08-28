<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `confirmed` documents the implicit `{field}_confirmation` partner, mirroring the field's type and
 * required flag. Runs after the type transformers so there's a resolved type to copy — every word of it,
 * since a field the rules left as either container has more than one, and a partner validated by the same
 * rule accepts exactly what the field does.
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
        $types = $field->types();
        $confirmation->setTypes($types === [] ? ['string'] : $types);

        if ($field->isRequired()) {
            $confirmation->markRequired();
        }
    }
}
