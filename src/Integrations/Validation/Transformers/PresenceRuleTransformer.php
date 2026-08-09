<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `required`/`present` mark the field required, `nullable` allows null, and `sometimes` (validate only if
 * present) forces it optional even alongside `required`. `filled` means "non-empty *when* present", so it
 * has no presence effect at all and a `filled` field stays optional.
 */
final class PresenceRuleTransformer implements RuleTransformer
{
    private const NAMES = ['required', 'nullable', 'sometimes', 'present', 'filled'];

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
        match ($rule->name) {
            'required', 'present' => $field->markRequired(),
            'nullable' => $field->markNullable(),
            'sometimes' => $field->markSometimes(),
            // `filled` is consumed but has no presence/schema effect on its own.
            default => null,
        };
    }
}
