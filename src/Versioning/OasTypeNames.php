<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

/**
 * OpenAPI's own type names, as a version verb may spell one in an attribute argument.
 *
 * This is deliberately NOT a second type grammar beside the one the rest of the product recovers out of
 * PHP. It reads the document's `type` keyword and nothing else: the six names the keyword itself takes,
 * plus `[]` for a list of them and `?` for one that may be null, which are the two shapes a scalar field
 * comes in and the two a removal verb would otherwise have no way to say. Anything richer belongs in the
 * code the chain reads, and a removal verb naming a class gets a `$ref` to it instead.
 *
 * `VersionRemovalTypeTest` walks every name and both suffixes, and pins what an unreadable spelling
 * degrades to.
 *
 * @internal
 */
final class OasTypeNames
{
    /**
     * The names the `type` keyword takes, less `null` — which says nothing on its own and is spelled
     * here as the `?` suffix on something that does.
     *
     * @var list<string>
     */
    public const array NAMES = ['string', 'integer', 'number', 'boolean', 'object', 'array'];

    /**
     * The subschema `$spelling` names, or null where it names none of them — which is the caller's cue
     * to publish an unconstrained shape and say so.
     *
     * The suffixes are read outermost first, so `string[]?` is a list of strings that may itself be
     * null and `string?[]` is a list whose members may be. Both nest, and a spelling that runs out of
     * characters before it reaches a name reads as nothing rather than as `string`.
     *
     * @return array<string, mixed>|null
     */
    public static function read(string $spelling): ?array
    {
        $type = trim($spelling);

        if (str_ends_with($type, '?')) {
            $inner = self::read(substr($type, 0, -1));

            return $inner === null ? null : self::nullable($inner);
        }

        if (str_ends_with($type, '[]')) {
            $inner = self::read(substr($type, 0, -2));

            return $inner === null ? null : ['type' => 'array', 'items' => $inner];
        }

        return in_array($type, self::NAMES, true) ? ['type' => $type] : null;
    }

    /**
     * `$schema` with `null` added to whatever it already says it is. A type list rather than a second
     * keyword: `nullable` is OAS 3.0's spelling and this product emits 3.1 and 3.2, where the union is
     * the type itself.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function nullable(array $schema): array
    {
        $type = $schema['type'] ?? null;
        $named = is_string($type) ? [$type] : array_values(array_filter(is_array($type) ? $type : [], is_string(...)));

        if (! in_array('null', $named, true)) {
            $named[] = 'null';
        }

        return ['type' => $named] + $schema;
    }
}
