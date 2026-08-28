<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Support\FieldPaths;

/*
 * What a set of recovered rule keys says about one field's container. Both questions read the keys as
 * PATHS, through the one grammar `FieldPath` owns — so an escaped dot is part of a name here exactly
 * as it is everywhere else, and a field's container is not decided by characters that happen to line up.
 */
it('finds a named child exactly where a rule names something inside the field', function (string $field, array $keys, bool $found): void {
    expect(FieldPaths::hasNamedChild($field, $keys))->toBe($found);
})->with([
    'a named child' => ['address', ['address', 'address.city'], true],
    'a numeric child, as an int-keyed shape writes it' => ['coords', ['coords', 'coords.0'], true],
    'a grandchild under a named child' => ['meta', ['meta', 'meta.scoring.value'], true],
    // Laravel applies a `*` rule to every value whatever the keys are, so it says nothing about key type.
    'a wildcard child is not one' => ['tags', ['tags', 'tags.*'], false],
    'a wildcard child with its own members is not one' => ['items', ['items', 'items.*.id'], false],
    'the field itself is not its own child' => ['address', ['address'], false],
    'nothing inside it at all' => ['address', ['address', 'other.city'], false],
    // A prefix of a SEGMENT is not a segment, so neither key is inside the other.
    'a shared segment prefix is not a parent' => ['met', ['met', 'meta.city'], false],
    // A purely numeric key reaches PHP as an INT, and still has to read as a path.
    'an int key is cast before it is read' => ['a', [0, 'a.b'], true],
    // The escape, read the way the rest of the body reads it: `meta\.scoring` is ONE field whose name
    // holds a dot, so it is not something inside `meta` however the characters line up…
    'a name holding a dot is not inside the name before it' => ['meta', ['meta', 'meta\.scoring'], false],
    // …and a field whose own name holds a dot still has children of its own.
    'a name holding a dot has children like any other' => ['meta\.scoring', ['meta\.scoring', 'meta\.scoring.value'], true],
    // The pair a string prefix gets wrong: `a\` is a name ending in a backslash and `a\.b` is the single
    // field `a.b`, so nothing here is inside anything. `str_starts_with($key, 'a\.')` said it was.
    'a trailing backslash is part of a name, not the start of an escape' => ['a\\', ['a\\', 'a\.b'], false],
    // `*` is a wildcard as a whole SEGMENT and not as a first character: `*id` is a name, so it is a
    // named child. A `str_starts_with(…, '*')` on the remainder read it as a wildcard.
    'a name merely beginning with a star is a named child' => ['items', ['items', 'items.*id'], true],
]);

it('finds any child, named or wildcard, exactly where a rule describes something inside the field', function (string $field, array $keys, bool $found): void {
    expect(FieldPaths::hasAnyChild($field, $keys))->toBe($found);
})->with([
    'a named child' => ['address', ['address', 'address.city'], true],
    'a wildcard child counts here' => ['tags', ['tags', 'tags.*'], true],
    'a wildcard member counts here' => ['items', ['items', 'items.*.id'], true],
    'the field itself is not its own child' => ['tags', ['tags'], false],
    'nothing inside it at all' => ['tags', ['tags', 'other.*'], false],
    'an int key is cast before it is read' => ['a', [0, 'a.*'], true],
    'a name holding a dot is not inside the name before it' => ['meta', ['meta', 'meta\.scoring'], false],
    'a name holding a dot has children like any other' => ['meta\.scoring', ['meta\.scoring', 'meta\.scoring.*'], true],
    // The same pair, and the same answer: the escape is resolved before anything is compared.
    'a trailing backslash is part of a name, not the start of an escape' => ['a\\', ['a\\', 'a\.b'], false],
]);
