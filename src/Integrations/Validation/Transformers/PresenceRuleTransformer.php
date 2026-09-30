<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Validation\BlankString;

/**
 * `required`/`present` mark the field required, `nullable` allows null, and `sometimes` (validate only if
 * present) forces it optional even alongside `required`. `filled` means "non-empty *when* present", so it
 * has no presence effect at all and a `filled` field stays optional.
 *
 * A blank string reaches a `nullable` field's rules as the null it accepts, so the field states that it
 * takes one ({@see BlankString}); `required` and `filled` refuse it.
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
            'nullable' => $this->nullable($field),
            'sometimes' => $field->markSometimes(),
            // `filled` has no presence effect: it only refuses an empty value when one is sent.
            default => null,
        };

        if (BlankString::refusedBy($rule->name)) {
            $field->refuseBlank();
        }
    }

    private function nullable(ValidationField $field): void
    {
        $field->markNullable();
        $field->admitBlank(BlankString::at($field->path()));
    }
}
