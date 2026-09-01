<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

/**
 * Where a verb that ADDS a member to a schema puts it, for both of the lists a member can be added to.
 *
 * One rule governs the two: **the position is a function of the schema and the member's name, never of
 * the order the verbs were written in.** An attribute set keeps the author's order within one
 * attribute type, so appending would make the answer depend on which declaration came first — two
 * documents derived from one codebase disagreeing about the order of a member neither disagrees about
 * the content of. Counting instead is commutative: whichever verb runs first, the pair lands the same
 * way round, and no entry already standing has to move.
 *
 * The two differ only in what supplies the order. `required` names members `properties` already lists,
 * so `properties` says which comes first. A member re-added by `#[RemovedResponseField]` is in neither
 * list and there is no such fact left in the code — the field was deleted — so the names themselves
 * are the order, which is the only total order over them that survives an unrelated field being added
 * beside it.
 *
 * `VersionMemberOrderTest` is the executed guard for the commutativity both claim.
 *
 * @internal
 */
final class MemberOrder
{
    /**
     * `$field` into a `required` list, at the index equal to the number of entries already standing that
     * `properties` puts before it.
     *
     * @param  list<mixed>  $required
     * @param  array<array-key, mixed>  $properties
     * @return list<mixed>
     */
    public static function intoRequired(array $required, array $properties, string $field): array
    {
        $positions = [];
        foreach (array_keys($properties) as $position => $name) {
            $positions[(string) $name] = $position;
        }

        $at = $positions[$field] ?? count($positions);

        $index = 0;
        foreach ($required as $name) {
            // A name `properties` does not carry sorts after everything it does, so it neither moves
            // nor is moved past — a `required` naming something under `additionalProperties` keeps its
            // place either way.
            if (($positions[is_string($name) ? $name : ''] ?? count($positions)) < $at) {
                $index++;
            }
        }

        return [...array_slice($required, 0, $index), $field, ...array_slice($required, $index)];
    }

    /**
     * `$field` into a `properties` map, at the index equal to the number of members already standing
     * whose name sorts before it.
     *
     * Byte order, and it is deliberately not "at the end". A re-added field appended would sit after
     * whatever the previous verb appended, so the two would come out in the order somebody wrote the
     * attributes; counting lands them the same way round either way. What it costs is that an old field
     * is interleaved among today's rather than collected at the end, which is the same trade
     * {@see intoRequired()} already makes and the same reason.
     *
     * @param  array<array-key, mixed>  $properties
     * @param  array<array-key, mixed>  $schema  the subschema to publish for it
     * @return array<array-key, mixed>
     */
    public static function intoProperties(array $properties, string $field, array $schema): array
    {
        $index = 0;
        foreach (array_keys($properties) as $name) {
            if (strcmp((string) $name, $field) < 0) {
                $index++;
            }
        }

        $position = 0;
        $inserted = [];
        foreach ($properties as $name => $value) {
            if ($position === $index) {
                $inserted[$field] = $schema;
            }

            $inserted[$name] = $value;
            $position++;
        }

        if ($position <= $index) {
            $inserted[$field] = $schema;
        }

        return $inserted;
    }
}
