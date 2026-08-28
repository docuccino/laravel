<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `min`, `max`, `between:a,b`, `size:n`, mapped to the keyword Laravel's own semantics imply: numeric →
 * `minimum`/`maximum`, array → `minItems`/`maxItems`, object → `minProperties`/`maxProperties`, anything
 * else → `minLength`/`maxLength`. Runs after {@see TypeRuleTransformer} and {@see FileRuleTransformer} so
 * the type is known; untyped falls back to string-length bounds, matching Laravel's coercion.
 *
 * Laravel sizes an array and an object identically — it counts the entries — so which of the two the
 * field is decides between two keyword pairs that mean the same thing to it and different things to a
 * validator reading the document. A length keyword on either is a bound nothing applies.
 *
 * A field the rules left as EITHER container therefore earns both pairs. Each keyword is inert against
 * the type it does not belong to, so the two together say what the one rule states — whichever container
 * the request turns out to carry, the count is bounded.
 *
 * On a file field these bounds are kilobytes, not string length, so `file|max:2048` must not become
 * `maxLength: 2048`. OpenAPI has no file-size keyword, so it becomes a description note instead.
 */
final class SizeRuleTransformer implements RuleTransformer
{
    private const NAMES = ['min', 'max', 'between', 'size'];

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
        if ($field->get('format') === 'binary') {
            $this->fileSize($field, $rule);

            return;
        }

        foreach ($this->keywords($field->types()) as [$minKeyword, $maxKeyword]) {
            match ($rule->name) {
                'min' => $this->write($field, $minKeyword, $rule->parameter()),
                'max' => $this->write($field, $maxKeyword, $rule->parameter()),
                'size' => $this->size($field, $minKeyword, $maxKeyword, $rule->parameter()),
                default => $this->between($field, $minKeyword, $maxKeyword, $rule),
            };
        }
    }

    /** A description note in KB rather than a wrong length keyword. Non-numeric parameters are skipped. */
    private function fileSize(ValidationField $field, ValidationRule $rule): void
    {
        $note = match ($rule->name) {
            'min' => is_numeric($rule->parameter()) ? sprintf('Minimum file size: %s KB.', $rule->parameter()) : null,
            'max' => is_numeric($rule->parameter()) ? sprintf('Maximum file size: %s KB.', $rule->parameter()) : null,
            'size' => is_numeric($rule->parameter()) ? sprintf('File size must be exactly %s KB.', $rule->parameter()) : null,
            default => is_numeric($rule->parameter(0)) && is_numeric($rule->parameter(1))
                ? sprintf('File size must be between %s and %s KB.', $rule->parameter(0), $rule->parameter(1))
                : null,
        };

        if ($note === null) {
            return;
        }

        $existing = $field->get('description');
        $field->set('description', is_string($existing) && $existing !== '' ? $existing.' '.$note : $note);
    }

    /**
     * The min/max keyword pair per type the field carries — several where several are true of it, and the
     * string-length fallback where nothing typed it, matching Laravel's coercion.
     *
     * @param  list<string>  $types
     * @return non-empty-list<array{0: string, 1: string}>
     */
    private function keywords(array $types): array
    {
        $pairs = [];
        foreach ($types as $type) {
            $pairs[] = match ($type) {
                'integer', 'number' => ['minimum', 'maximum'],
                'array' => ['minItems', 'maxItems'],
                'object' => ['minProperties', 'maxProperties'],
                default => ['minLength', 'maxLength'],
            };
        }

        return $pairs === [] ? [['minLength', 'maxLength']] : $pairs;
    }

    private function between(ValidationField $field, string $minKeyword, string $maxKeyword, ValidationRule $rule): void
    {
        $this->write($field, $minKeyword, $rule->parameter(0));
        $this->write($field, $maxKeyword, $rule->parameter(1));
    }

    private function size(ValidationField $field, string $minKeyword, string $maxKeyword, ?string $value): void
    {
        $this->write($field, $minKeyword, $value);
        $this->write($field, $maxKeyword, $value);
    }

    private function write(ValidationField $field, string $keyword, ?string $value): void
    {
        if ($value === null || ! is_numeric($value)) {
            return;
        }

        $field->set($keyword, str_contains($value, '.') ? (float) $value : (int) $value);
    }
}
