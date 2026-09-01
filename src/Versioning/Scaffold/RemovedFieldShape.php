<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning\Scaffold;

use Docuccino\Core\Support\Hydrate;
use Docuccino\Laravel\Versioning\OasTypeNames;

/**
 * How the scaffolder spells the `type:` of a field a version removed, read off the shape the older
 * artifact still publishes for it.
 *
 * It spells only what `#[RemovedResponseField]` reads, and it is the same three readings in the same
 * order: a class the document publishes becomes `Thing::class`, one of OpenAPI's own type names
 * becomes that name (with `?` and `[]` for the two suffixes the verb knows), and anything else spells
 * NOTHING — which the verb publishes as an unconstrained field and says so. Guessing a spelling the
 * verb would then fail to read is worse than leaving it out: the author is told, once, that the shape
 * is the one thing they have to supply.
 *
 * @internal
 */
final class RemovedFieldShape
{
    /** How a class reference is spelled in an attribute argument, and the marker this class returns it by. */
    private const string CLASS_SUFFIX = '::class';

    /**
     * The `type:` for `$property`, or null where it names nothing the verb can read.
     *
     * @param  array<string, mixed>  $property  the subschema the OLD artifact published for the field
     * @param  array<string, string>  $classesByRef  `$ref` pointer => the class the head publishes it for
     */
    public static function spell(array $property, array $classesByRef): ?string
    {
        $ref = Hydrate::stringOrNull($property['$ref'] ?? null);
        if ($ref !== null) {
            $fqcn = $classesByRef[$ref] ?? null;

            return $fqcn === null ? null : $fqcn.self::CLASS_SUFFIX;
        }

        return self::fromType($property, $classesByRef);
    }

    /** The FQCN a spelling imports, or null where it imports nothing. */
    public static function classOf(?string $type): ?string
    {
        return $type !== null && str_ends_with($type, self::CLASS_SUFFIX)
            ? substr($type, 0, -strlen(self::CLASS_SUFFIX))
            : null;
    }

    /**
     * The `type` keyword, spelled the way the verb reads it back. Only the shapes {@see OasTypeNames}
     * knows — a name, a list of one, and a nullable union — because a spelling it cannot read publishes
     * an unconstrained field plus a diagnostic, which is strictly worse than saying nothing.
     *
     * @param  array<string, mixed>  $property
     * @param  array<string, string>  $classesByRef
     */
    private static function fromType(array $property, array $classesByRef): ?string
    {
        $type = $property['type'] ?? null;

        if (is_array($type)) {
            $named = array_values(array_filter(Hydrate::stringList($type), static fn (string $name): bool => $name !== 'null'));

            // A union of two or more real types is not a `type` this verb can spell, and `?` on its own
            // says nothing about what it holds.
            if (count($named) !== 1 || ! in_array('null', $type, true)) {
                return null;
            }

            $inner = self::fromType(['type' => $named[0]] + $property, $classesByRef);

            return $inner === null ? null : $inner.'?';
        }

        if (! is_string($type) || ! in_array($type, OasTypeNames::NAMES, true)) {
            return null;
        }

        if ($type !== 'array') {
            return $type;
        }

        // A list spells its member type, which is the one place this recurses — and a list of a class
        // is not spellable, since the `[]` suffix reads an OpenAPI type name and nothing else.
        $items = Hydrate::map($property['items'] ?? null);
        $member = $items === [] ? null : self::spell($items, $classesByRef);

        return $member === null || self::classOf($member) !== null ? 'array' : $member.'[]';
    }
}
