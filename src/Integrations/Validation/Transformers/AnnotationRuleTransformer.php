<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * The three annotation keywords a rule can state outright — `format`, `description`, `example`. No
 * Laravel rule carries these; they exist so an author's `#[RuleSchema]` reaches the schema through the
 * chain like everything else, rather than writing keywords behind it.
 *
 * `format` never overwrites one a type rule already implied, and a description is appended to any note
 * an earlier rule left, so nothing is lost whichever order the rules arrive in.
 */
final class AnnotationRuleTransformer implements RuleTransformer
{
    private const NAMES = ['format', 'description', 'example'];

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
        $value = $rule->parameter();
        if ($value === null || $value === '') {
            return;
        }

        match ($rule->name) {
            'format' => $field->has('format') ? null : $field->set('format', $value),
            'description' => $this->describe($field, $value),
            default => $field->set('example', $this->typed($value, $field->type())),
        };
    }

    private function describe(ValidationField $field, string $description): void
    {
        $existing = $field->get('description');

        $field->set('description', is_string($existing) && $existing !== '' ? $existing.' '.$description : $description);
    }

    /** Parameters are strings by the time a rule reaches the chain; coerce back to the field's type. */
    private function typed(string $value, ?string $type): string|int|float|bool
    {
        return match ($type) {
            'integer' => (int) $value,
            'number' => (float) $value,
            'boolean' => $value === 'true' || $value === '1',
            default => $value,
        };
    }
}
