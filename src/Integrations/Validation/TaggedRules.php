<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\TaggedVariants;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Validation\Transformers\ChoiceRuleTransformer;
use Docuccino\Laravel\Support\ListValueNames;

/**
 * Finds the objects whose members are partitioned by one of them — the tag — and proves each one exactly or
 * leaves it alone. Proved means every member of the object is either unconditional or has, as its FIRST rule,
 * an `exclude_unless`/`exclude_if` on the tag with literal values; the tag is required and limited to a closed
 * set of plain strings; and no other conditional rule is in the mix. Laravel then keeps, for each tag value, a
 * set of members read straight off those lists, so the object is published as one branch per value
 * ({@see TaggedVariants}) and the presence rules that now say it are moved off the members.
 *
 * Anything else — a second tag, a condition this build cannot read, a `required_if`, a member whose own
 * children are validated before it is excluded — stays the merged object the rest of the chain documents.
 * Laravel's semantics this rests on: `docs/design/uir-and-extensions.md` §Tagged request objects.
 */
final class TaggedRules
{
    /** The two exclude rules whose condition is a literal comparison with another field's value. */
    private const GATES = ['exclude_unless', 'exclude_if'];

    /** Rules the tag may carry beside its presence and its value set, none of which can reject a value in it. */
    private const TAG_RULES = ['string', 'bail', 'description', 'example'];

    /** Presence rules a gated member's branch reads as "the key is sent". */
    private const PRESENCE = ['required', 'present'];

    /** The rules that refuse an ABSENT key, and so an empty object holding a member that carries one. */
    private const REFUSE_ABSENT = [...self::PRESENCE, 'accepted', 'declined'];

    /** Rules on the object itself that pass an empty one sent under its key, whatever their parameters. */
    private const PASS_EMPTY = ['array', 'nullable', 'present', 'sometimes', 'bail', 'description', 'example'];

    /** Rules on the object itself that refuse an empty one sent under its key. */
    private const REFUSE_EMPTY = ['required', 'filled'];

    /**
     * The rule set with every proved object's partition recorded and its presence rules moved onto it, the
     * rules as written kept beside as its merged reading ({@see RuleSet}).
     */
    public static function split(RuleSet $rules): RuleSet
    {
        if ($rules->variants !== []) {
            return $rules;
        }

        $fields = $rules->fields;
        $variants = [];
        foreach (self::candidates($fields) as $parent => $tag) {
            $proved = self::prove($fields, $parent, $tag);
            if ($proved === null) {
                continue;
            }

            [$fields, $variants[]] = $proved;
        }

        return $variants === [] ? $rules : new RuleSet($fields, $variants, $rules->fields);
    }

    /**
     * Each object with a member gated on a sibling, and that sibling — where every gate in the object
     * names the same one. An object gated on two different siblings is no candidate.
     *
     * @param  array<string, list<ValidationRule>>  $fields
     * @return array<string, string>
     */
    private static function candidates(array $fields): array
    {
        $tags = [];
        foreach ($fields as $key => $rules) {
            // A purely numeric key reaches PHP as an INT array key, and a path is read as a string.
            $key = (string) $key;
            $gate = $rules[0] ?? null;
            $other = $gate?->parameter();
            if ($gate === null || ! in_array($gate->name, self::GATES, true) || $other === null) {
                continue;
            }

            [$parent] = TaggedVariants::ownerAndMember($key);
            [$otherParent, $tag] = TaggedVariants::ownerAndMember($other);
            if ($otherParent === $parent && $tag !== '*' && ! str_contains($key.$other, '\\')) {
                $tags[$parent][$tag] = true;
            }
        }

        $candidates = [];
        foreach ($tags as $parent => $named) {
            if (count($named) === 1) {
                $candidates[(string) $parent] = (string) array_key_first($named);
            }
        }

        return $candidates;
    }

    /**
     * The fields with this object's presence rules moved onto its partition, and the partition — or null
     * where the rules do not prove one.
     *
     * @param  array<string, list<ValidationRule>>  $fields
     * @return array{array<string, list<ValidationRule>>, TaggedVariants}|null
     */
    private static function prove(array $fields, string $parent, string $tag): ?array
    {
        $prefix = $parent === '' ? '' : $parent.'.';
        $tagKey = $prefix.$tag;
        $tagRules = $fields[$tagKey] ?? null;
        if ($tagRules === null || self::hasChildren($fields, $tagKey)) {
            return null;
        }

        $gate = self::tagDomain($tagRules, $parent);
        if ($gate === null) {
            return null;
        }
        [$values, $note, $admitsEmpty] = $gate;

        $keys = array_map(strval(...), array_keys($fields));
        $gated = [];
        $shared = [];
        $out = $fields;
        $out[$tagKey] = self::without($tagRules, $parent, self::PRESENCE);

        foreach ($keys as $position => $key) {
            [$owner, $member] = TaggedVariants::ownerAndMember($key);
            if ($owner !== $parent || $key === $tagKey) {
                continue;
            }

            if ($member === '*') {
                return null;
            }

            $rules = $fields[$key];
            $first = $rules[0] ?? null;

            if ($first !== null && in_array($first->name, self::GATES, true) && $first->parameter() === $tagKey) {
                $rest = array_slice($rules, 1);
                $listed = array_slice($first->parameters, 1);
                if ($listed === [] || self::conditional($rest, $parent) || ! self::childrenFollow($keys, $key, $position)) {
                    return null;
                }

                $requires = self::requires($rest, $parent);
                $gated[$member] = ['keep' => $first->name === 'exclude_unless', 'values' => $listed, 'requires' => $requires];
                $out[$key] = self::without($rest, $parent, self::PRESENCE);

                // Laravel only tests an `exclude_if` against a tag that was SENT; on an empty object this
                // member is kept, and a presence rule on it then refuses the empty object.
                $admitsEmpty = $admitsEmpty && ($first->name === 'exclude_unless' || ! self::insists($rest, self::REFUSE_ABSENT));

                continue;
            }

            if (self::conditional($rules, $parent)) {
                return null;
            }

            if (self::requiredWithParent($rules, $parent) && ! self::named($rules, 'sometimes')) {
                $shared[] = $member;
            }
            $out[$key] = self::without($rules, $parent, []);
            $admitsEmpty = $admitsEmpty && ! self::insists($rules, self::REFUSE_ABSENT);
        }

        if ($gated === []) {
            return null;
        }

        // The object's own rules run on the empty object too, and one this cannot read leaves the answer
        // open — neither shape can be published without guessing, so the object stays merged.
        if ($admitsEmpty) {
            $admitsEmpty = self::acceptsEmpty($fields[$parent] ?? []);
            if ($admitsEmpty === null) {
                return null;
            }
        }

        $names = ChoiceRuleTransformer::names($values, $note);
        $word = self::pathWord($parent);

        $branches = [];
        $kept = [];
        foreach ($values as $index => $value) {
            $members = [];
            $required = $shared;
            foreach ($gated as $member => $gate) {
                if (in_array($value, $gate['values'], true) === $gate['keep']) {
                    $members[] = (string) $member;
                    if ($gate['requires']) {
                        $required[] = (string) $member;
                    }
                }
            }

            $kept[implode("\0", $members)] = true;
            $branches[] = ['value' => $value, 'name' => $word.$names[$index], 'members' => $members, 'required' => $required];
        }

        // Where every value keeps the same members the tag selects nothing, and the merged object says it all.
        if (count($kept) < 2) {
            return null;
        }

        return [$out, new TaggedVariants($parent, $tag, array_map(strval(...), array_keys($gated)), $branches, $admitsEmpty)];
    }

    /**
     * What the tag accepts — its values, the enum class they came from, and whether an empty object is
     * accepted beside them — or null where the tag is not required, not a closed set of plain strings, or
     * carries a rule that could refuse one of them.
     *
     * The values must not be numeric: Laravel compares the tag loosely, and only between two strings that
     * are not both numeric is that the same as comparing them exactly.
     *
     * @param  list<ValidationRule>  $rules
     * @return array{list<string>, ?string, bool}|null
     */
    private static function tagDomain(array $rules, string $parent): ?array
    {
        $values = null;
        $note = null;
        $presence = null;

        foreach ($rules as $rule) {
            if (in_array($rule->name, ['in', 'enum'], true) && $values === null) {
                $values = $rule->parameters;
                $note = $rule->note;
            } elseif ($rule->name === 'required' && $presence === null) {
                $presence = 'required';
            } elseif (self::isRequiredWithParent($rule, $parent) && $presence === null) {
                // Required only while the object is sent non-empty, so the empty object is accepted too.
                $presence = 'required_with';
            } elseif (! in_array($rule->name, self::TAG_RULES, true)) {
                return null;
            }
        }

        if ($values === null || $values === [] || $presence === null) {
            return null;
        }

        foreach ($values as $value) {
            if ($value === '' || is_numeric($value)) {
                return null;
            }
        }

        return [array_values(array_unique($values)), $note, $presence === 'required_with'];
    }

    /**
     * Whether the object's own rules pass it sent empty — a JSON `{}`, which reaches Laravel as an empty
     * array — or null where one of them is a rule this does not know the answer for. A size rule is read
     * as Laravel reads an array's: by its count, which is 0.
     *
     * @param  list<ValidationRule>  $rules
     */
    private static function acceptsEmpty(array $rules): ?bool
    {
        foreach ($rules as $rule) {
            if (in_array($rule->name, self::PASS_EMPTY, true)) {
                continue;
            }

            if (in_array($rule->name, self::REFUSE_EMPTY, true)) {
                return false;
            }

            $bounds = array_map(static fn (string $bound): ?float => is_numeric($bound) ? (float) $bound : null, $rule->parameters);
            $passes = match ($rule->name) {
                'min' => isset($bounds[0]) ? $bounds[0] <= 0 : null,
                'max' => isset($bounds[0]) ? $bounds[0] >= 0 : null,
                'size' => isset($bounds[0]) ? $bounds[0] === 0.0 : null,
                'between' => isset($bounds[0], $bounds[1]) ? $bounds[0] <= 0 && $bounds[1] >= 0 : null,
                default => null,
            };

            if ($passes !== true) {
                return $passes;
            }
        }

        return true;
    }

    /**
     * Whether any rule's effect depends on whether or what another field was sent — the rules a partition by
     * the tag alone cannot state. `required_with` on the object itself is not one: on every branch the object
     * carries its tag, so it is sent non-empty and the rule reads as `required`.
     *
     * @param  list<ValidationRule>  $rules
     */
    private static function conditional(array $rules, string $parent): bool
    {
        foreach ($rules as $rule) {
            if (self::isRequiredWithParent($rule, $parent)) {
                continue;
            }

            if (preg_match('/^(required_|present_|missing|prohibit|exclude|accepted_if|declined_if)/', $rule->name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a gated member's remaining rules require it on the branches that keep it.
     *
     * @param  list<ValidationRule>  $rules
     */
    private static function requires(array $rules, string $parent): bool
    {
        return self::insists($rules) || (self::requiredWithParent($rules, $parent) && ! self::named($rules, 'sometimes'));
    }

    /**
     * Whether the rules require the key be sent whatever else was — one of `$names` that `sometimes` does
     * not make optional again.
     *
     * @param  list<ValidationRule>  $rules
     * @param  list<string>  $names
     */
    private static function insists(array $rules, array $names = self::PRESENCE): bool
    {
        if (self::named($rules, 'sometimes')) {
            return false;
        }

        foreach ($names as $name) {
            if (self::named($rules, $name)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<ValidationRule>  $rules */
    private static function named(array $rules, string $name): bool
    {
        foreach ($rules as $rule) {
            if ($rule->name === $name) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<ValidationRule>  $rules */
    private static function requiredWithParent(array $rules, string $parent): bool
    {
        foreach ($rules as $rule) {
            if (self::isRequiredWithParent($rule, $parent)) {
                return true;
            }
        }

        return false;
    }

    private static function isRequiredWithParent(ValidationRule $rule, string $parent): bool
    {
        return $parent !== '' && $rule->name === 'required_with' && $rule->parameters === [$parent];
    }

    /**
     * The rules less `required_with` on the object and the named presence rules — what the branches state instead.
     *
     * @param  list<ValidationRule>  $rules
     * @param  list<string>  $presence
     * @return list<ValidationRule>
     */
    private static function without(array $rules, string $parent, array $presence): array
    {
        return array_values(array_filter(
            $rules,
            static fn (ValidationRule $rule): bool => ! self::isRequiredWithParent($rule, $parent) && ! in_array($rule->name, $presence, true),
        ));
    }

    /**
     * @param  array<string, list<ValidationRule>>  $fields
     */
    private static function hasChildren(array $fields, string $key): bool
    {
        foreach (array_keys($fields) as $field) {
            if (str_starts_with((string) $field, $key.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every key under a gated member comes after it. Laravel validates keys in the order written, and
     * one written first has run before the member is excluded — so its rules hold on every request.
     *
     * @param  list<string>  $keys
     */
    private static function childrenFollow(array $keys, string $key, int $position): bool
    {
        foreach (array_slice($keys, 0, $position) as $earlier) {
            if (str_starts_with($earlier, $key.'.')) {
                return false;
            }
        }

        return true;
    }

    /** The object's path as a word, `*` segments left out: `delivery` → `Delivery`, `items.*` → `Items`. */
    private static function pathWord(string $parent): string
    {
        if ($parent === '') {
            return '';
        }

        $word = '';
        foreach (explode('.', $parent) as $segment) {
            if ($segment !== '*') {
                $word .= ListValueNames::names([$segment])[0];
            }
        }

        return $word;
    }
}
