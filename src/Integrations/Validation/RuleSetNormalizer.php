<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Support\FieldPaths;
use Docuccino\Laravel\Integrations\Validation\Transformers\SizeRuleTransformer;

/**
 * Makes a recovered field map coherent before the rule chain runs — the two facts only visible ACROSS
 * fields, which a per-field {@see RuleTransformer} cannot see. A bare `prohibited` field and everything
 * under it is dropped, since the API refuses it outright (the conditional forms and `prohibits` stay —
 * those fields are sendable); and a field with a named, non-`*` child key trades its `array`/`list` rule
 * for `object`, because Laravel's `array` covers objects too and `{"type": "array", "properties": …}`
 * validates nothing. Every recovery integration runs it alongside {@see RuleOrdering}.
 *
 * The trade is a rewrite rather than a deletion because the type-aware rules downstream READ the type: a
 * field left with no type word at all takes its size bounds as string lengths, and which of the two
 * containers it is decides that keyword ({@see SizeRuleTransformer}).
 */
final class RuleSetNormalizer
{
    /** Type rules meaning "PHP array", which a named child key resolves to an object. */
    private const ARRAY_RULES = ['array', 'list'];

    /** The word the rule vocabulary lacks for what a named child key proves. */
    private const OBJECT_RULE = 'object';

    public function normalize(RuleSet $rules): RuleSet
    {
        $fields = $this->withoutProhibited($rules->fields);

        $keys = array_keys($fields);

        $out = [];
        foreach ($fields as $field => $fieldRules) {
            $out[$field] = FieldPaths::hasNamedChild((string) $field, $keys)
                ? self::asObject($fieldRules)
                : $fieldRules;
        }

        return new RuleSet($out);
    }

    /**
     * @param  array<string, list<ValidationRule>>  $fields
     * @return array<string, list<ValidationRule>>
     */
    private function withoutProhibited(array $fields): array
    {
        $prohibited = [];
        foreach ($fields as $field => $fieldRules) {
            foreach ($fieldRules as $rule) {
                if ($rule->name === 'prohibited') {
                    // Cast for the same reason {@see FieldPaths::hasNamedChild()} does: a purely numeric
                    // field key reaches PHP as an INT array key, and a path is read as a string.
                    $prohibited[] = (string) $field;
                    break;
                }
            }
        }

        if ($prohibited === []) {
            return $fields;
        }

        $out = [];
        foreach ($fields as $key => $fieldRules) {
            $field = (string) $key;
            foreach ($prohibited as $dropped) {
                if ($field === $dropped || str_starts_with($field, $dropped.'.')) {
                    continue 2;
                }
            }
            $out[$key] = $fieldRules;
        }

        return $out;
    }

    /**
     * The same rules with each array word replaced by `object`, in place so the type still lands ahead of
     * every rule that reads it. A field stating the word twice keeps one.
     *
     * @param  list<ValidationRule>  $rules
     * @return list<ValidationRule>
     */
    private static function asObject(array $rules): array
    {
        $out = [];
        $stated = false;

        foreach ($rules as $rule) {
            if (! in_array($rule->name, self::ARRAY_RULES, true)) {
                $out[] = $rule;

                continue;
            }

            if (! $stated) {
                $out[] = ValidationRule::of(self::OBJECT_RULE);
                $stated = true;
            }
        }

        return $out;
    }
}
