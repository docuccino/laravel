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

        $reference = $this->reference($enum, $rule->note, $context);
        if ($reference !== null) {
            // The component states the type, the values and their whole vocabulary. Setting any of it
            // here as well would publish one fact in two places — which is the duplication the
            // reference exists to remove.
            $field->setReference($reference);

            return;
        }

        $field->set('enum', $enum);
        $this->decorate($enum, $rule->note, $field, $context);
    }

    /**
     * The `$ref` this field publishes instead of its own copy of the set, or null where it keeps one:
     * no enum class behind the rule, a class the policy does not hoist, or — the one that matters —
     * a rule stating FEWER cases than the enum has. A subset is a narrower domain than the component
     * publishes, and referencing it would tell a consumer the server accepts values it rejects.
     *
     * @param  list<int|string>  $enum
     * @return array<string, string>|null
     */
    private function reference(array $enum, ?string $note, SchemaContext $context): ?array
    {
        if ($note === null || ! $this->statesEveryCase($enum, $note) || ! EnumComponent::hoists($note, $context)) {
            return null;
        }

        $body = EnumComponent::body($note, $context);

        return $body === null ? null : EnumComponent::reference($note, $body, $context);
    }

    /**
     * Whether the rule's values are the enum's whole case set. Compared as SETS — a rule may list them
     * in any order and still be the same domain — but never as a subset: `Rule::enum(X::class)->only(…)`
     * accepts fewer values than the component publishes, and a `$ref` to it would tell a consumer the
     * server takes values it will reject.
     *
     * @param  list<int|string>  $enum
     */
    private function statesEveryCase(array $enum, string $fqcn): bool
    {
        if (! enum_exists($fqcn)) {
            return false;
        }

        $cases = array_map(strval(...), EnumReflection::values($fqcn));
        $stated = array_map(strval(...), $enum);

        sort($cases);
        sort($stated);

        return $cases !== [] && $cases === $stated;
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
