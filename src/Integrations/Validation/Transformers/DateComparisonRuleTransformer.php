<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `before`, `before_or_equal`, `after`, `after_or_equal`. The bound is a runtime relationship OpenAPI can't
 * express, so it becomes a description. A `date`/`date-time` format is only emitted when the target is
 * itself a parseable date or date keyword; a bare field reference like `after:start_date` is described but
 * left unformatted, since that field could be anything.
 */
final class DateComparisonRuleTransformer implements RuleTransformer
{
    private const NAMES = ['before', 'before_or_equal', 'after', 'after_or_equal'];

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
        $target = $rule->parameter();
        if ($target === null || $target === '') {
            return;
        }

        if (! $field->has('type')) {
            $field->setType('string');
        }

        if ($this->isDateTarget($target) && ! $field->has('format')) {
            $field->set('format', $this->hasTime($target) ? 'date-time' : 'date');
        }

        $note = sprintf('%s %s.', $this->phrase($rule->name), $target);
        $existing = $field->get('description');
        $field->set('description', is_string($existing) && $existing !== '' ? $existing.' '.$note : $note);
    }

    private function phrase(string $name): string
    {
        return match ($name) {
            'before' => 'Must be a date before',
            'before_or_equal' => 'Must be a date on or before',
            'after' => 'Must be a date after',
            default => 'Must be a date on or after',
        };
    }

    /** A parseable date literal or a relative keyword makes the `format` claim clean; a field ref does not. */
    private function isDateTarget(string $target): bool
    {
        return @strtotime($target) !== false;
    }

    private function hasTime(string $target): bool
    {
        return str_contains($target, ':') || strtolower($target) === 'now';
    }
}
