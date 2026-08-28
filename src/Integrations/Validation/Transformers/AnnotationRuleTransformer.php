<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Schema\TypedExample;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * The three annotation keywords a rule can state outright — `format`, `description`, `example`. No
 * Laravel rule carries these; they exist so an author's `#[RuleSchema]` reaches the schema through the
 * chain like everything else, rather than writing keywords behind it.
 *
 * `format` never overwrites one a type rule already implied, and a description is appended to any note
 * an earlier rule left, so nothing is lost whichever order the rules arrive in. A rule parameter is a
 * string by the time it reaches the chain, so a stated example is read back through
 * {@see TypedExample} — the same reading every other producer of an authored example uses.
 */
final class AnnotationRuleTransformer implements RuleTransformer
{
    private const NAMES = ['format', 'description', 'example'];

    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return self::NAMES;
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $value = $rule->parameter();
        if ($value === null || $value === '') {
            return;
        }

        match ($rule->name) {
            'format' => $field->has('format') ? null : $field->set('format', $value),
            'description' => $this->describe($field, $value),
            default => $this->exemplify($field, $value, $context),
        };
    }

    /**
     * A stated example, read as the type the constraint rules settled — {@see RuleOrdering} puts the
     * annotations last so that type is final by the time this runs. Every word of that type: a field the
     * rules left as either container carries several, and reading against one of them would drop an
     * example the other accepts. One that does not read as any publishes nothing and says so, rather
     * than a coercion's `0` for `n/a`.
     */
    private function exemplify(ValidationField $field, string $value, SchemaContext $context): void
    {
        $types = $field->types();
        $example = TypedExample::of($value, $types);

        if ($example === null) {
            $context->diagnostic(TypedExample::untypable('field "'.$field->path().'"', $value, $types));

            return;
        }

        $field->set('example', $example[0]);
    }

    private function describe(ValidationField $field, string $description): void
    {
        $existing = $field->get('description');

        $field->set('description', is_string($existing) && $existing !== '' ? $existing.' '.$description : $description);
    }
}
