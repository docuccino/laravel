<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Schema\EnumComponent;
use Docuccino\Core\Extensions\Schema\EnumDecoration;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Support\ListValueNames;

/**
 * `in:a,b,c` and `enum` (a folded `Rule::enum(…)`/`Rule::in(…)`) → an `enum` of the allowed values.
 * Numeric-only sets get an integer type, everything else string.
 *
 * Where the rule folded from an enum class and lists ALL of its cases, the field publishes a `$ref`
 * to that enum's component instead of a second copy of it — the same component a response property of
 * that type uses, so one enum is one named type in a generated client rather than one per direction.
 * A rule listing a SUBSET keeps its own values: the component's set is wider than what this endpoint
 * accepts, and referencing it would mark values valid that the server rejects.
 *
 * A field publishing the whole set INLINE — the enum-components policy off, so there is no component
 * anywhere — still carries the sentence the enum states about itself, because one enum is one described
 * type however a document reaches it. A SUBSET carries none: the field's `enum` is a narrower domain
 * than the one that sentence is about, and prose describing a set the field does not publish is the
 * confident-but-wrong answer the same rule refuses the `$ref` for.
 *
 * The set is decorated like every other value set the document publishes ({@see EnumDecoration}):
 * a validation rule is a closed set the application enforces, so it owes the same SDK member names a
 * component enum and an allow-list parameter carry — without them a generated client has values and
 * no way to name them, and `1` is not an identifier in any target language. Where the rule folded
 * from an enum class, the names and `#[CaseDescription]` prose are that enum's own.
 */
final class ChoiceRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['in', 'enum'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $values = $rule->parameters;
        if ($values === []) {
            return;
        }

        $allNumeric = true;
        foreach ($values as $value) {
            if (! is_numeric($value) || str_contains($value, '.')) {
                $allNumeric = false;
                break;
            }
        }

        if (! $field->has('type')) {
            $field->setType($allNumeric ? 'integer' : 'string');
        }

        $enum = $allNumeric ? array_map('intval', $values) : $values;

        // The enum's declaring file answers the values, the names and the prose, whichever way this
        // goes, so it keys the fragment: adding a case, or a #[CaseDescription], re-documents the
        // request that lists it.
        $file = $rule->note === null ? null : EnumReflection::file($rule->note);
        if ($file !== null) {
            $context->dependsOn($file);
        }

        $wholeEnum = $this->wholeEnum($enum, $rule->note);

        $reference = $wholeEnum === null ? null : $this->reference($wholeEnum, $context);
        if ($reference !== null) {
            // The component states the type, the values and their whole vocabulary. Setting any of it
            // here as well would publish one fact in two places — which is the duplication the
            // reference exists to remove.
            $field->setReference($reference);

            return;
        }

        $field->set('enum', $enum);
        $this->decorate($enum, $rule->note, $field, $context);

        if ($wholeEnum !== null) {
            $this->describe($wholeEnum, $field, $context);
        }
    }

    /**
     * The enum class whose WHOLE case set this rule publishes, or null where none does: no enum class
     * behind the rule, or — the one that matters — a rule stating FEWER cases than the enum has.
     * Compared as SETS, since a rule may list them in any order and still be the same domain.
     *
     * The one question both the `$ref` and the enum's own sentence hang off, because both are facts
     * about the enum's whole domain: `Rule::enum(X::class)->only(…)` publishes a narrower one, so a
     * `$ref` to the component would tell a consumer the server takes values it rejects, and the
     * sentence would describe a set this field does not accept.
     *
     * @param  list<int|string>  $enum
     */
    private function wholeEnum(array $enum, ?string $fqcn): ?string
    {
        if ($fqcn === null || ! enum_exists($fqcn)) {
            return null;
        }

        $cases = array_map(strval(...), EnumReflection::values($fqcn));
        $stated = array_map(strval(...), $enum);

        sort($cases);
        sort($stated);

        return $cases !== [] && $cases === $stated ? $fqcn : null;
    }

    /**
     * The `$ref` this field publishes instead of its own copy of the set, or null where the policy does
     * not hoist this class — an un-autoloadable enum, or `enums.components` off.
     *
     * @return array<string, string>|null
     */
    private function reference(string $fqcn, SchemaContext $context): ?array
    {
        if (! EnumComponent::hoists($fqcn, $context)) {
            return null;
        }

        $body = EnumComponent::body($fqcn, $context);

        return $body === null ? null : EnumComponent::reference($fqcn, $body, $context);
    }

    /**
     * The sentence the enum states about itself, onto a field publishing its set inline — where no
     * component exists to carry it, so the field does or nothing does. Read and refused by
     * {@see EnumComponent::description()} on the same terms as any other schema class's.
     *
     * A description already on the field is this field's own and outranks the type's; a `#[RuleSchema]`
     * one arrives after this rule ({@see RuleOrdering}) and appends to this, as it appends to any note an
     * earlier rule left — which is the `$ref` form's two descriptions flattened into the one slot an
     * inline field has.
     */
    private function describe(string $fqcn, ValidationField $field, SchemaContext $context): void
    {
        $description = EnumComponent::description($fqcn, $context);

        if ($description !== null && ! $field->has('description')) {
            $field->set('description', $description);
        }
    }

    /**
     * The decoration for the published set, applied keyword by keyword because a field is patched
     * rather than replaced. `enum` itself is already set and is skipped — the decoration is computed
     * against the very values published, which is what keeps the parallel arrays in step with them.
     *
     * @param  list<int|string>  $enum
     */
    private function decorate(array $enum, ?string $note, ValidationField $field, SchemaContext $context): void
    {
        $decorated = EnumDecoration::apply(
            ['enum' => $enum],
            $context->representation()->enumNaming,
            $this->names($enum, $note),
            $note === null ? [] : EnumReflection::descriptions($note),
        );

        foreach ($decorated as $keyword => $value) {
            if ($keyword !== 'enum' && ! $field->has($keyword)) {
                $field->set($keyword, $value);
            }
        }
    }

    /**
     * The member names for the set: the enum's own case names where the rule folded from one and every
     * published value is one of its cases — matched BY VALUE, because a rule may list a subset or another
     * order and names applied by position would put a case's name on its neighbour. Failing that, names
     * minted from the values themselves, exactly as every other published value set is named.
     *
     * @param  list<int|string>  $enum
     * @return list<string>
     */
    private function names(array $enum, ?string $note): array
    {
        $strings = array_map(strval(...), $enum);

        if ($note !== null && enum_exists($note)) {
            $cases = array_map(strval(...), EnumReflection::values($note));
            $byValue = array_combine($cases, EnumReflection::names($note));

            $mapped = [];
            foreach ($strings as $value) {
                if (! isset($byValue[$value])) {
                    $mapped = [];
                    break;
                }

                $mapped[] = $byValue[$value];
            }

            if ($mapped !== []) {
                return $mapped;
            }
        }

        return ListValueNames::names($strings);
    }
}
