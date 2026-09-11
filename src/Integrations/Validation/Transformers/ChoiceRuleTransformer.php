<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Schema\EnumDecoration;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Support\ListValueNames;

/**
 * `in:a,b,c` and `enum` (a folded `Rule::enum(…)`/`Rule::in(…)`) → an `enum` of the allowed values.
 * Numeric-only sets get an integer type, everything else string.
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
        $field->set('enum', $enum);

        $this->decorate($enum, $rule->note, $field, $context);
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
        // The enum's declaring file answers the names and the prose, so it keys the fragment: adding a
        // case, or a #[CaseDescription], has to re-document the request that lists it.
        $file = $note === null ? null : EnumReflection::file($note);
        if ($file !== null) {
            $context->dependsOn($file);
        }

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
