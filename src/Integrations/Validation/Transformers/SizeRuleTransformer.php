<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * Size rules (`min`, `max`, `between:a,b`, `size:n`), applied type-aware (Laravel's own semantics):
 * a numeric field bounds `minimum`/`maximum`, an array bounds `minItems`/`maxItems`, and any other
 * field bounds `minLength`/`maxLength`. Runs after {@see TypeRuleTransformer} + {@see FileRuleTransformer}
 * so the field type is known; an untyped field defaults to string-length bounds, matching Laravel's
 * default coercion.
 *
 * On a FILE field (`format: binary`) these bounds mean KILOBYTES, not string length — emitting
 * `maxLength: 2048` for `file|max:2048` is actively wrong. OpenAPI has no file-size keyword, so the
 * honest representation is a human description note; no numeric length keyword is emitted.
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

        [$minKeyword, $maxKeyword] = $this->keywords($field->type());

        match ($rule->name) {
            'min' => $this->write($field, $minKeyword, $rule->parameter()),
            'max' => $this->write($field, $maxKeyword, $rule->parameter()),
            'size' => $this->size($field, $minKeyword, $maxKeyword, $rule->parameter()),
            default => $this->between($field, $minKeyword, $maxKeyword, $rule),
        };
    }

    /**
     * A size bound on a file field is in KB. Append a human note rather than emit a wrong length/size
     * keyword; skip non-numeric parameters.
     */
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
     * @return array{0: string, 1: string}
     */
    private function keywords(?string $type): array
    {
        return match ($type) {
            'integer', 'number' => ['minimum', 'maximum'],
            'array' => ['minItems', 'maxItems'],
            default => ['minLength', 'maxLength'],
        };
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
