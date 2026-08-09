<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `starts_with` / `ends_with` (Scribe-parity, validation §4 #8). A single required affix maps cleanly
 * to an anchored `pattern` (the literal is regex-escaped); a multi-value any-of set has no single
 * pattern, so it degrades to a human description ("Must start with one of: …"). Defaults an untyped
 * field to string.
 */
final class AffixRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['starts_with', 'ends_with'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $values = $rule->parameters;
        if ($values === []) {
            return;
        }

        if (! $field->has('type')) {
            $field->setType('string');
        }

        if (count($values) === 1) {
            $escaped = self::escape($values[0]);
            $field->set('pattern', $rule->name === 'starts_with' ? '^'.$escaped : $escaped.'$');

            return;
        }

        $note = $rule->name === 'starts_with'
            ? sprintf('Must start with one of: %s.', implode(', ', $values))
            : sprintf('Must end with one of: %s.', implode(', ', $values));

        $existing = $field->get('description');
        $field->set('description', is_string($existing) && $existing !== '' ? $existing.' '.$note : $note);
    }

    /** Escape ECMA-262 regex metacharacters in a literal affix. */
    private static function escape(string $literal): string
    {
        return (string) preg_replace('/[.*+?^${}()|[\]\\\\]/', '\\\\$0', $literal);
    }
}
