<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `starts_with`/`ends_with`, and their negations. A single affix becomes an anchored `pattern` with the literal
 * regex-escaped — under `not` for `doesnt_start_with`/`doesnt_end_with`, which an escaped literal states
 * exactly; a multi-value set, or a negation on a field already carrying a `not`, degrades to a note. Untyped
 * fields default to string.
 */
final class AffixRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['starts_with', 'ends_with', 'doesnt_start_with', 'doesnt_end_with'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $values = $rule->parameters;
        if ($values === []) {
            return;
        }

        // Only refusing, so it says nothing about the type of the rest: Laravel takes `5` under
        // `doesnt_start_with:a`, and the field keeps whatever type its other rules give it.
        if ($rule->name === 'doesnt_start_with' || $rule->name === 'doesnt_end_with') {
            $this->refuse($rule->name === 'doesnt_start_with', $values, $field);

            return;
        }

        if (! $field->has('type')) {
            $field->setType('string');
        }

        // The affix itself, so the example carries the required literal rather than a filler the
        // anchored pattern would reject. First value where several are legal — the list is authored.
        $field->proposeExample($rule->name === 'starts_with' ? $values[0].'example' : 'example'.$values[0]);

        if (count($values) === 1) {
            $escaped = self::escape($values[0]);
            $field->set('pattern', $rule->name === 'starts_with' ? '^'.$escaped : $escaped.'$');

            return;
        }

        $note = $rule->name === 'starts_with'
            ? sprintf('Must start with one of: %s.', implode(', ', $values))
            : sprintf('Must end with one of: %s.', implode(', ', $values));

        $field->note($note);
    }

    /**
     * The negation: a string the anchored literal matches is refused, and nothing else is — `type` inside the
     * `not`, since a bare `pattern` holds of every non-string and its negation would refuse them all. Only one
     * `not` fits on a schema, so a second negation, or several affixes, is said in a note instead.
     *
     * @param  non-empty-list<string>  $values
     */
    private function refuse(bool $start, array $values, ValidationField $field): void
    {
        if (count($values) === 1 && ! $field->has('not')) {
            $escaped = self::escape($values[0]);
            $field->set('not', ['type' => 'string', 'pattern' => $start ? '^'.$escaped : $escaped.'$']);

            return;
        }

        $field->note(sprintf($start ? 'Must not start with any of: %s.' : 'Must not end with any of: %s.', implode(', ', $values)));
    }

    /** Escape ECMA-262 regex metacharacters in a literal affix. */
    private static function escape(string $literal): string
    {
        return (string) preg_replace('/[.*+?^${}()|[\]\\\\]/', '\\\\$0', $literal);
    }
}
