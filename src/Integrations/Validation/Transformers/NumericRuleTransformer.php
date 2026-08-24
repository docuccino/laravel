<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `multiple_of` → `multipleOf`; `decimal` → a `number` with a decimal-places description; and the
 * numeric-literal forms of `gt`/`gte`/`lt`/`lte` → `exclusiveMinimum`/`minimum`/`exclusiveMaximum`/`maximum`.
 * The comparisons also accept a field reference (`gt:other_field`), which is not a bound — that form is
 * described, never read as a literal. Untyped fields default to `number`.
 */
final class NumericRuleTransformer implements RuleTransformer
{
    private const NAMES = ['decimal', 'multiple_of', 'gt', 'gte', 'lt', 'lte'];

    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, self::NAMES, true);
    }

    public function handledRuleNames(): array
    {
        return self::NAMES;
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        match ($rule->name) {
            'decimal' => $this->decimal($field, $rule),
            'multiple_of' => $this->multipleOf($field, $rule),
            default => $this->comparison($field, $rule),
        };
    }

    private function decimal(ValidationField $field, ValidationRule $rule): void
    {
        $this->defaultNumber($field);

        $min = $rule->parameter(0);
        $max = $rule->parameter(1);
        if ($min === null || ! ctype_digit($min)) {
            return;
        }

        $note = $max !== null && ctype_digit($max)
            ? sprintf('Must have between %s and %s decimal places.', $min, $max)
            : sprintf('Must have %s decimal places.', $min);

        $this->describe($field, $note);

        // JSON numbers carry no trailing zeros, so no literal can state "two decimal places" — an
        // example would be a value this endpoint rejects.
        $field->proposeExample(null);
    }

    private function multipleOf(ValidationField $field, ValidationRule $rule): void
    {
        $value = $rule->parameter();
        if ($value === null || ! is_numeric($value)) {
            return;
        }

        $this->defaultNumber($field);
        $field->set('multipleOf', $this->number($value));
    }

    private function comparison(ValidationField $field, ValidationRule $rule): void
    {
        $value = $rule->parameter();
        if ($value === null) {
            return;
        }

        if (! is_numeric($value)) {
            // A field reference — a runtime relationship, described not constrained. Nothing constant
            // is provably on its legal side, so the example is withdrawn with it.
            $this->describe($field, sprintf('%s %s.', $this->phrase($rule->name), $value));
            $field->proposeExample(null);

            return;
        }

        $this->defaultNumber($field);
        $keyword = match ($rule->name) {
            'gt' => 'exclusiveMinimum',
            'gte' => 'minimum',
            'lt' => 'exclusiveMaximum',
            default => 'maximum',
        };
        $field->set($keyword, $this->number($value));
    }

    private function phrase(string $name): string
    {
        return match ($name) {
            'gt' => 'Must be greater than',
            'gte' => 'Must be greater than or equal to',
            'lt' => 'Must be less than',
            default => 'Must be less than or equal to',
        };
    }

    private function defaultNumber(ValidationField $field): void
    {
        if (! $field->has('type')) {
            $field->setType('number');
        }
    }

    private function number(string $value): int|float
    {
        return str_contains($value, '.') ? (float) $value : (int) $value;
    }

    private function describe(ValidationField $field, string $note): void
    {
        $existing = $field->get('description');
        $field->set('description', is_string($existing) && $existing !== '' ? $existing.' '.$note : $note);
    }
}
