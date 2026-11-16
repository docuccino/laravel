<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use ReflectionEnum;

/**
 * The values a choice list (`in`, `enum`, `not_in`) publishes. Laravel compares the sent value's string
 * with each entry, so where a type rule types the field, the list is published in that type and an entry
 * no value of it spells is dropped: `string|in:1,2` takes `"1"` and refuses `1`. With no type rule, an
 * all-integer list reads as integers and anything else as strings.
 *
 * A value is published only where its own string is listed, which is how Laravel 13 compares. Laravel 12's
 * loose comparison also takes other spellings (`"01"` for `"1"`, `1` for `01`), which the document leaves
 * out, as `integer` publishes integers though the server takes `"3"`.
 */
final class ChoiceValues
{
    /**
     * @param  list<string>  $types  the field's type words, as {@see ValidationField::types()} reads them
     * @return list<bool|float|int|string>
     */
    public static function of(ValidationRule $rule, array $types): array
    {
        $type = count($types) === 1 ? $types[0] : null;
        if (! in_array($type, ['string', 'integer', 'number', 'boolean'], true)) {
            return self::untyped($rule->parameters);
        }

        // `Rule::enum` asks the enum's `tryFrom()`, which reads a number as an integer first.
        $tryFrom = $rule->name === 'enum';

        $out = [];
        foreach ($rule->parameters as $value) {
            $number = $type === 'integer' || $type === 'number' ? self::number($value) : null;

            $cast = match ($type) {
                'string' => $value,
                'integer' => is_int($number) ? $number : null,
                'number' => $tryFrom && ! is_int($number) ? null : $number,
                'boolean' => self::boolean($value, $tryFrom ? $rule->note : null),
            };

            if ($cast !== null) {
                $out[] = $cast;
            }
        }

        return $out;
    }

    /**
     * Every scalar, of any type, whose string is listed: the list read where nothing types the values.
     *
     * @return list<bool|float|int|string>
     */
    public static function anyType(ValidationRule $rule): array
    {
        $out = [];
        foreach (['string', 'number', 'boolean'] as $type) {
            foreach (self::of($rule, [$type]) as $value) {
                if (! in_array($value, $out, true)) {
                    $out[] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $values
     * @return list<int>|list<string>
     */
    private static function untyped(array $values): array
    {
        foreach ($values as $value) {
            if (! is_numeric($value) || str_contains($value, '.')) {
                return $values;
            }
        }

        return array_map(intval(...), $values);
    }

    /** The number whose string the entry is, or null where it is none. */
    private static function number(string $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;
        $number = floor($float) === $float && abs($float) <= 2 ** 53 ? (int) $float : $float;

        return (string) $number === $value ? $number : null;
    }

    /**
     * The boolean whose string the entry is, or null where it is neither's: PHP spells `true` as `"1"` and
     * `false` as `""`. An int-backed enum's `tryFrom()` reads `false` as 0.
     */
    private static function boolean(string $value, ?string $enum): ?bool
    {
        $false = $enum !== null && enum_exists($enum) && (string) (new ReflectionEnum($enum))->getBackingType() === 'int' ? '0' : '';

        return match ($value) {
            '1' => true,
            $false => false,
            default => null,
        };
    }
}
