<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Validation\DeclaredFields;
use Docuccino\Core\Extensions\Validation\RecoveredRequest;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
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
     * Rules are not the only way to answer it. A declaration that SETTLES the field
     * ({@see DeclaredFields::decidesContainer()}) says what the container is at a layer that outranks
     * this one, so the document will not say "either" and asking for rules that would say it again is a
     * note fired where nothing can be done. A declaration that settles nothing is not one of those, and
     * standing the note down for it would leave the field wider than the rules left it with nothing said.
     *
     * `$sourceClass` is here because a body has two declaration sites, and it has no default: a caller
     * with a source class that passed no argument would read one of them, so PHP refuses the call rather
     * than a consumer finding the note in the document. `null` is a caller saying there is no class — an
     * inline `validate()`. Which sites there are at all is
     * {@see RecoveredRequest::declaredFields()}'s, shared with the rules recoverers' own notes so none
     * of them can drift.
     */
    public static function report(RuleSet $normalized, RouteContext $context, ?string $sourceClass): void
    {
        $undecided = self::undecidedFields($normalized);
        if ($undecided === []) {
            return;
        }

        $declared = RecoveredRequest::declaredFields($context, $sourceClass);

        foreach ($undecided as $field) {
            if ($declared->decidesContainer($field)) {
                continue;
            }

            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'validation.container-undecided',
                message: sprintf(
                    'Validation field "%s" is an array rule with no item or key rules, so a JSON array and a JSON object both satisfy it; it is documented as either.',
                    $field,
                ),
                help: self::undecidedHelp($field, RecoveredRequest::documentsBody($context)),
                routeSignature: $context->route->signature(),
            ));
        }
    }

    /**
     * What clears the note for one field. Rules clear it wherever they land; a declaration clears it on
     * the terms its own layer writes on ({@see DeclaredFields}), so the sentence naming one has to name
     * the layer this route's rules actually reach — a body, where a key named inside the field proves
     * the container by being written there, or query parameters, which are one parameter per name and
     * decide nothing but by their own `type:`.
     */
    private static function undecidedHelp(string $field, bool $body): string
    {
        $declaration = $body
            ? 'A #[BodyParameter] naming a key inside "%1$s", or naming it with a type of its own, answers it too — the one for a free-form map with no keys to enumerate.'
            : 'A #[QueryParameter] naming "%1$s" with a type of its own answers it too — `object` for a free-form map with no keys to enumerate.';

        return sprintf(
            'Add "%1$s.*" rules for a list, or dotted "%1$s.<key>" rules for an object, and the document states the one the endpoint means. '.$declaration,
            $field,
        );
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
