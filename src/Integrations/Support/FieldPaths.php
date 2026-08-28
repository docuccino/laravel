<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Validation\FieldPath;

/**
 * What a recovered rule set's dotted field keys say about a field's container. One definition, because
 * two readers ask it: the normalizer, deciding whether a field's `array` word survives, and the Data
 * recovery, deciding whether an override replaced a recovered container or only restated it.
 *
 * The distinction that matters is NAMED child versus `*`: a named key proves the field is an object,
 * whereas Laravel applies a `field.*` rule to every value whatever the keys are, so a `*` child says
 * nothing about key type and never decides list-vs-map. A field with NO child key of either kind is a
 * third answer again: nothing states what is inside it, so nothing states which container it is.
 *
 * Both questions are answered through {@see FieldPath}, which owns the grammar these keys are written
 * in — a string prefix would read `\.` as two segments and `a\` as an ancestor of the field `a.b`.
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
        $depth = count(FieldPath::segments($field));

        foreach ($keys as $key) {
            // A purely numeric field key reaches PHP as an INT array key, so cast before reading it as
            // a path — the same reason FieldNode casts its property names.
            $other = (string) $key;
            if ($other === $field || ! FieldPath::isAtOrUnder($other, $field)) {
                continue;
            }

            $segments = FieldPath::segments($other);
            if (count($segments) > $depth && $segments[$depth] !== '*') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any of these keys is a child of the field at all, named or `*` — `tags.*` and
     * `address.city` both count. False means no rule anywhere describes what is inside the field.
     *
     * @param  list<array-key>  $keys  as `array_keys()` hands them over
     */
    public static function hasAnyChild(string $field, array $keys): bool
    {
        foreach ($keys as $key) {
            $other = (string) $key;
            if ($other !== $field && FieldPath::isAtOrUnder($other, $field)) {
                return true;
            }
        }

        return false;
    }
}
