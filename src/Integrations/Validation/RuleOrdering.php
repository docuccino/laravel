<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * Sorts each field's rules into Laravel's effect sequence — presence/type, then constraints, then
 * cross-field — so `['max:100', 'string']` documents the same as `['string', 'max:100']`; a size rule has
 * to see the type first. Stable within a rank, so author order survives among equal-rank rules and output
 * stays deterministic. This is Laravel vocabulary, hence its home here: the core chain driver applies
 * rules in whatever order it's handed.
 *
 * Shared by every recovery integration (FormRequest, inline validate, Spatie Data) so all rule sets
 * normalise identically before the chain runs.
 */
final class RuleOrdering
{
    /**
     * Effect-order rank per rule name; unlisted (custom) rules run in the middle band.
     *
     * @var array<string, int>
     */
    private const RANK = [
        'required' => 0, 'present' => 0, 'filled' => 0, 'sometimes' => 0, 'nullable' => 0,
        'string' => 10, 'integer' => 10, 'int' => 10, 'numeric' => 10, 'boolean' => 10,
        'bool' => 10, 'array' => 10, 'email' => 10, 'uuid' => 10, 'ulid' => 10, 'url' => 10,
        'ip' => 10, 'date' => 10, 'date_format' => 10, 'json' => 10, 'list' => 10,
        'accepted' => 10, 'accepted_if' => 10, 'declined' => 10, 'declined_if' => 10,
        'file' => 15, 'image' => 15,
        'in' => 20, 'enum' => 20, 'not_in' => 20, 'exists' => 20, 'unique' => 20,
        'regex' => 25, 'alpha' => 25, 'alpha_num' => 25, 'alpha_dash' => 25,
        'starts_with' => 25, 'ends_with' => 25, 'timezone' => 25,
        'digits' => 25, 'digits_between' => 25, 'max_digits' => 25, 'min_digits' => 25,
        'before' => 25, 'before_or_equal' => 25, 'after' => 25, 'after_or_equal' => 25,
        'min' => 30, 'max' => 30, 'between' => 30, 'size' => 30,
        'decimal' => 30, 'multiple_of' => 30, 'gt' => 30, 'gte' => 30, 'lt' => 30, 'lte' => 30,
        'distinct' => 30,
        'confirmed' => 40,
        // Annotations last: a stated description appends to any note the constraint rules left, and a
        // stated format/example sees the final resolved type.
        'format' => 50, 'description' => 50, 'example' => 50,
    ];

    public function order(RuleSet $rules): RuleSet
    {
        $fields = [];
        foreach ($rules->fields as $field => $fieldRules) {
            $fields[$field] = $this->orderField($fieldRules);
        }

        return new RuleSet($fields);
    }

    /**
     * @param  list<ValidationRule>  $rules
     * @return list<ValidationRule>
     */
    private function orderField(array $rules): array
    {
        $indexed = [];
        foreach ($rules as $position => $rule) {
            $indexed[] = ['rule' => $rule, 'rank' => self::RANK[$rule->name] ?? 22, 'position' => $position];
        }

        usort($indexed, static fn (array $a, array $b): int => [$a['rank'], $a['position']] <=> [$b['rank'], $b['position']]);

        return array_map(static fn (array $entry): ValidationRule => $entry['rule'], $indexed);
    }
}
