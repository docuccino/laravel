<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * What a recovered rule set's dotted field keys say about a field's container. One definition, because
 * two readers ask it: the normalizer, deciding whether a field's `array` word survives, and the Data
 * recovery, deciding whether an override replaced a recovered container or only restated it.
 *
 * The distinction that matters is NAMED child versus `*`: a named key proves the field is an object,
 * whereas Laravel applies a `field.*` rule to every value whatever the keys are, so a `*` child says
 * nothing about key type and never decides list-vs-map.
 */
final class FieldPaths
{
    /**
     * Whether any of these keys is a named (non-`*`) child of the field — `address.city`, or the
     * `coords.0` an int-keyed shape writes, but never `tags.*` or `items.*.id`.
     *
     * @param  list<array-key>  $keys  as `array_keys()` hands them over
     */
    public static function hasNamedChild(string $field, array $keys): bool
    {
        $prefix = $field.'.';
        foreach ($keys as $key) {
            // A purely numeric field key reaches PHP as an INT array key, so cast before reading it as
            // a path — the same reason FieldNode casts its property names.
            $other = (string) $key;
            if ($other !== $field && str_starts_with($other, $prefix) && ! str_starts_with(substr($other, strlen($prefix)), '*')) {
                return true;
            }
        }

        return false;
    }
}
