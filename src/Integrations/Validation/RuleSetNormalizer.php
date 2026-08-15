<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * Makes a recovered field map coherent before the rule chain runs — the two facts only visible ACROSS
 * fields, which a per-field {@see RuleTransformer} cannot see. A bare `prohibited` field and everything
 * under it is dropped, since the API refuses it outright (the conditional forms and `prohibits` stay —
 * those fields are sendable); and a field with a named, non-`*` child key loses its `array`/`list` rule,
 * because Laravel's `array` covers objects too and `{"type": "array", "properties": …}` validates nothing.
 * Every recovery integration runs it alongside {@see RuleOrdering}.
 */
final class RuleSetNormalizer
{
    /** Type rules meaning "PHP array", which a named child key resolves to an object. */
    private const ARRAY_RULES = ['array', 'list'];

    public function normalize(RuleSet $rules): RuleSet
    {
        $fields = $this->withoutProhibited($rules->fields);

        $out = [];
        foreach ($fields as $field => $fieldRules) {
            $out[$field] = $this->hasNamedChild($field, $fields)
                ? array_values(array_filter($fieldRules, static fn (ValidationRule $rule): bool => ! in_array($rule->name, self::ARRAY_RULES, true)))
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
                    $prohibited[] = $field;
                    break;
                }
            }
        }

        if ($prohibited === []) {
            return $fields;
        }

        $out = [];
        foreach ($fields as $field => $fieldRules) {
            foreach ($prohibited as $dropped) {
                if ($field === $dropped || str_starts_with($field, $dropped.'.')) {
                    continue 2;
                }
            }
            $out[$field] = $fieldRules;
        }

        return $out;
    }

    /**
     * Whether any other field path is a named (non-`*`) child of this one.
     *
     * @param  array<string, list<ValidationRule>>  $fields
     */
    private function hasNamedChild(string $field, array $fields): bool
    {
        $prefix = $field.'.';
        foreach (array_keys($fields) as $other) {
            if ($other !== $field && str_starts_with($other, $prefix) && ! str_starts_with(substr($other, strlen($prefix)), '*')) {
                return true;
            }
        }

        return false;
    }
}
