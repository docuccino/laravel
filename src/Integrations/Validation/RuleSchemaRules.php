<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Attributes\RuleSchema;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * Maps a `#[RuleSchema]` onto the rule vocabulary, so an annotated custom rule documents through the
 * same transformer chain as a string rule rather than writing schema keywords of its own:
 *
 *   type → the matching type rule · enum → `in:…` · pattern → `regex:…` · min/max → the size rules ·
 *   format/description/example → the annotation rules ({@see Transformers\AnnotationRuleTransformer}).
 *
 * An unrecognised `type` passes through as a rule name, so `email`/`uuid` work and a typo surfaces as
 * the usual `validation.rule-unhandled` diagnostic instead of vanishing.
 */
final class RuleSchemaRules
{
    /** JSON Schema type name → the Laravel type rule that produces it, where the two differ. */
    private const TYPE_ALIASES = ['number' => 'numeric'];

    /**
     * @return list<ValidationRule>
     */
    public static function of(RuleSchema $schema): array
    {
        $rules = [];

        if ($schema->type !== null && trim($schema->type) !== '') {
            $type = strtolower(trim($schema->type));
            $rules[] = ValidationRule::of(self::TYPE_ALIASES[$type] ?? $type);
        }

        if ($schema->enum !== null && $schema->enum !== []) {
            $rules[] = ValidationRule::of('in', array_map(strval(...), $schema->enum));
        }

        if ($schema->pattern !== null && $schema->pattern !== '') {
            $rules[] = ValidationRule::of('regex', [self::delimited($schema->pattern)]);
        }

        if ($schema->min !== null) {
            $rules[] = ValidationRule::of('min', [self::number($schema->min)]);
        }

        if ($schema->max !== null) {
            $rules[] = ValidationRule::of('max', [self::number($schema->max)]);
        }

        if ($schema->format !== null && $schema->format !== '') {
            $rules[] = ValidationRule::of('format', [$schema->format]);
        }

        if ($schema->description !== null && $schema->description !== '') {
            $rules[] = ValidationRule::of('description', [$schema->description]);
        }

        if ($schema->example !== null) {
            $rules[] = ValidationRule::of('example', [self::scalar($schema->example)]);
        }

        return $rules;
    }

    /**
     * The regex transformer strips one layer of delimiters, so a bare ECMA-262 pattern is wrapped to
     * survive the round trip; an already-delimited one is left alone.
     */
    private static function delimited(string $pattern): string
    {
        return strlen($pattern) > 1 && str_starts_with($pattern, '/') && str_ends_with($pattern, '/')
            ? $pattern
            : '/'.$pattern.'/';
    }

    private static function number(int|float $value): string
    {
        return is_int($value) ? (string) $value : rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }

    private static function scalar(string|int|float|bool $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => self::number($value),
            default => (string) $value,
        };
    }
}
