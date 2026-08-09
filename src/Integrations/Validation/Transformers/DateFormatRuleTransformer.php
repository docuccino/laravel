<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `date_format:Y-m-d` → a string schema whose `format` is `date` (date-only patterns) or
 * `date-time` (patterns carrying a time token), with the raw PHP format preserved in the
 * description so a reader keeps the exact contract.
 */
final class DateFormatRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['date_format'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $field->setType('string');

        $pattern = $rule->parameter() ?? '';
        $hasTime = (bool) preg_match('/[HGhisvuAaeTPO]/', $pattern);
        $field->set('format', $hasTime ? 'date-time' : 'date');

        if ($pattern !== '' && ! $field->has('description')) {
            $field->set('description', sprintf('Expected format: %s', $pattern));
        }
    }
}
