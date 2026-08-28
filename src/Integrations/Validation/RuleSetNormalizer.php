<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Validation\FieldPath;
use Docuccino\Core\Extensions\Validation\RecoveredRequest;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\TypeGrammar\TypeStringParser;
use Docuccino\Laravel\Integrations\Support\FieldPaths;
use Docuccino\Laravel\Integrations\Validation\Transformers\SizeRuleTransformer;

/**
 * Makes a recovered field map coherent before the rule chain runs — the facts only visible ACROSS
 * fields, which a per-field {@see RuleTransformer} cannot see. A bare `prohibited` field and everything
 * under it is dropped, since the API refuses it outright (the conditional forms and `prohibits` stay —
 * those fields are sendable). The rest is one question asked of every field: `array` is the one word
 * Laravel's vocabulary has for both containers, so what the field's CHILD keys say decides which it is.
 * A named, non-`*` child key proves an object, and the `array`/`list` word trades for `object` — a
 * `{"type": "array", "properties": …}` validates nothing. No child key at all proves nothing, and the
 * word trades for {@see UNDECIDED_RULE}: a JSON array and a JSON object both pass those rules, so the
 * document says both rather than picking one. Every recovery integration runs this alongside
 * {@see RuleOrdering}, and reports what it could not decide ({@see undecidedFields()}).
 *
 * Each trade is a rewrite rather than a deletion because the type-aware rules downstream READ the type: a
 * field left with no type word at all takes its size bounds as string lengths, and which container it is
 * decides that keyword ({@see SizeRuleTransformer}).
 */
final class RuleSetNormalizer
{
    /** Type rules meaning "PHP array", which a named child key resolves to an object. */
    private const ARRAY_RULES = ['array', 'list'];

    /** The word the rule vocabulary lacks for what a named child key proves. */
    private const OBJECT_RULE = 'object';

    /** The word it lacks for the container nothing decided — a value that may be either. */
    public const UNDECIDED_RULE = 'array_or_object';

    /**
     * Words that settle the container on their own, so an `array` standing beside one is a restatement
     * rather than an open question. `list` is Laravel's word for sequential keys; the other two are
     * synthesised by a recovery that read the shape from a type.
     */
    private const DECIDING_RULES = ['list', 'object', 'additional_properties'];

    public function normalize(RuleSet $rules): RuleSet
    {
        $fields = $this->withoutProhibited($rules->fields);

        $keys = array_keys($fields);

        $out = [];
        foreach ($fields as $field => $fieldRules) {
            $path = (string) $field;
            $out[$field] = match (true) {
                FieldPaths::hasNamedChild($path, $keys) => self::asObject($fieldRules),
                ! FieldPaths::hasAnyChild($path, $keys) => self::asUndecided($fieldRules),
                default => $fieldRules,
            };
        }

        return new RuleSet($out);
    }

    /**
     * Say out loud what the trade above could not decide: one info per field whose container the rules
     * left open, so the widening reaches the author rather than degrading quietly. Every recovery
     * integration calls this with the set it just normalized.
     *
     * Rules are not the only way to answer it. A `#[BodyParameter]` that SETTLES the field — see
     * {@see settles()} for what that takes — says what the container is at a layer that outranks this
     * one, so the document will not say "either" and asking for rules that would say it again is a note
     * fired where nothing can be done. A declaration that settles nothing is not one of those, and
     * standing the note down for it would leave the field wider than the rules left it with nothing said.
     *
     * Only where a body is written at all: this runs ahead of the verb branch, and a read verb sends the
     * same rules to QUERY parameters ({@see RecoveredRequest::documentsBody()}), which a declaration
     * about the body cannot reach. Reading the declarations there would stand the note down for a
     * parameter nothing had answered the question for.
     */
    public static function report(RuleSet $normalized, RouteContext $context): void
    {
        $undecided = self::undecidedFields($normalized);
        if ($undecided === []) {
            return;
        }

        $declared = RecoveredRequest::documentsBody($context)
            ? $context->attributes->all(BodyParameter::class)
            : [];
        $types = new TypeStringParser;

        foreach ($undecided as $field) {
            foreach ($declared as $attribute) {
                if (self::settles($attribute, $field, $types)) {
                    continue 2;
                }
            }

            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'validation.container-undecided',
                message: sprintf(
                    'Validation field "%s" is an array rule with no item or key rules, so a JSON array and a JSON object both satisfy it; it is documented as either.',
                    $field,
                ),
                help: sprintf(
                    'Add "%1$s.*" rules for a list, or dotted "%1$s.<key>" rules for an object, and the document states the one the endpoint means. A #[BodyParameter] naming a key inside "%1$s", or naming it with a type of its own, answers it too — the one for a free-form map with no keys to enumerate.',
                    $field,
                ),
                routeSignature: $context->route->signature(),
            ));
        }
    }

    /**
     * Whether one declaration answers the container question for `$field` — asked of the declaration
     * rather than of its name, since a name is only where a declaration points. A path naming something
     * strictly INSIDE the field settles it by existing: a key proves an object, a `*` proves a list. A
     * path naming the field ITSELF settles it only as far as its type does, read by the parser that will
     * do the writing, because a guard recognising fewer spellings than the fold it protects is a hole —
     * `array` and `mixed` resolve to no shape and publish the empty schema, which decides neither
     * container, while no type at all publishes the attribute's own default of `string`. A path with an
     * empty segment names no field and documents nothing, so there is nothing for it to have settled.
     *
     * A well-formed path the body then turns out not to be able to carry — a scalar, a composition or a
     * `$ref` parent — still stands the note down: this runs during recovery, with no body yet to ask, and
     * the refusal is reported where it happens, against the declaration itself, where a second note
     * asking for rules would name the wrong remedy for the same mistake.
     */
    private static function settles(BodyParameter $attribute, string $field, TypeStringParser $types): bool
    {
        if (! FieldPath::isWellFormed($attribute->name) || ! FieldPath::isAtOrUnder($attribute->name, $field)) {
            return false;
        }

        if (count(FieldPath::segments($attribute->name)) > count(FieldPath::segments($field))) {
            return true;
        }

        return $attribute->type === null || ! $types->parseDeclared($attribute->type) instanceof UnknownT;
    }

    /**
     * The fields this normalizer could not decide a container for, in rule-set order. Read off the
     * normalized set, so it names exactly the fields the trade above touched.
     *
     * @return list<string>
     */
    private static function undecidedFields(RuleSet $normalized): array
    {
        $fields = [];
        foreach ($normalized->fields as $field => $rules) {
            foreach ($rules as $rule) {
                if ($rule->name === self::UNDECIDED_RULE) {
                    $fields[] = (string) $field;
                    break;
                }
            }
        }

        return $fields;
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

    /**
     * The same rules with a lone `array` word traded for the undecided one. A field whose rules already
     * settle the container keeps them: `list` states sequential keys, and the two synthesised words state
     * an object, so an `array` beside any of them restates rather than asks.
     *
     * @param  list<ValidationRule>  $rules
     * @return list<ValidationRule>
     */
    private static function asUndecided(array $rules): array
    {
        $names = array_map(static fn (ValidationRule $rule): string => $rule->name, $rules);

        if (! in_array('array', $names, true) || array_intersect(self::DECIDING_RULES, $names) !== []) {
            return $rules;
        }

        return array_map(
            static fn (ValidationRule $rule): ValidationRule => $rule->name === 'array'
                ? ValidationRule::of(self::UNDECIDED_RULE)
                : $rule,
            $rules,
        );
    }
}
