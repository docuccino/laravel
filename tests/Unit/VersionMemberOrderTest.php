<?php

declare(strict_types=1);

use Docuccino\Laravel\Versioning\MemberOrder;

/**
 * The one property both of {@see MemberOrder}'s answers exist for, stated here independently of the
 * code that computes it: **adding two members lands them the same way round whichever went in first.**
 *
 * A guard that asked the implementation for its own rule would agree with whatever the implementation
 * did, so this asserts the property rather than the algorithm — every ordered pair of a small alphabet,
 * over bases that are sorted, reversed and neither.
 */

/**
 * The bases both rules are exercised over. The reversed one matters: a rule that only holds where the
 * list is already in order is a rule that holds nowhere in a real schema, because `properties` keeps
 * the order the code declares.
 *
 * @return array<string, list<string>>
 */
function memberOrderBases(): array
{
    return [
        'an empty schema' => [],
        'a sorted list' => ['alpha', 'beta', 'gamma'],
        'a reversed one' => ['gamma', 'beta', 'alpha'],
        'one in neither order' => ['id', 'title', 'publishedAt'],
    ];
}

/** @return list<string> */
function memberOrderNames(): array
{
    return ['aardvark', 'id', 'publishedAt', 'subtotal', 'title', 'zebra'];
}

it('lands two re-added properties the same way round whichever went in first', function (string $label): void {
    $base = memberOrderBases()[$label];
    /** @var array<string, array<string, mixed>> $properties */
    $properties = array_fill_keys($base, ['type' => 'string']);

    $disagreements = [];
    $pairs = 0;

    foreach (memberOrderNames() as $first) {
        foreach (memberOrderNames() as $second) {
            if ($first === $second || in_array($first, $base, true) || in_array($second, $base, true)) {
                continue;
            }

            $pairs++;

            $written = MemberOrder::intoProperties(
                MemberOrder::intoProperties($properties, $first, ['type' => 'integer']),
                $second,
                ['type' => 'boolean'],
            );
            $reversed = MemberOrder::intoProperties(
                MemberOrder::intoProperties($properties, $second, ['type' => 'boolean']),
                $first,
                ['type' => 'integer'],
            );

            if (array_keys($written) !== array_keys($reversed)) {
                $disagreements[] = $first.' + '.$second.': '.implode(',', array_keys($written)).' vs '.implode(',', array_keys($reversed));
            }
        }
    }

    // A sweep that stopped sweeping must fail rather than agree with an empty set.
    expect($pairs)->toBeGreaterThan(5)
        ->and($disagreements)->toBe([]);
})->with(array_keys(memberOrderBases()));

it('lands two required entries the same way round whichever went in first', function (string $label): void {
    $base = memberOrderBases()[$label];
    // Both names have to be IN `properties` for the required rule to have an opinion about them, and
    // the schema's own order is what it reads — so the properties list is the base plus the pair, in
    // one fixed order, and only the two insertions are swapped.
    $properties = array_fill_keys([...$base, 'subtotal', 'archivedAt'], ['type' => 'string']);

    $written = MemberOrder::intoRequired(MemberOrder::intoRequired($base, $properties, 'subtotal'), $properties, 'archivedAt');
    $reversed = MemberOrder::intoRequired(MemberOrder::intoRequired($base, $properties, 'archivedAt'), $properties, 'subtotal');

    expect($written)->toBe($reversed)
        // And neither entry already standing moved: an insertion that reordered the list would make a
        // version document disagree with itself about members nobody changed.
        ->and(array_values(array_filter($written, static fn (mixed $name): bool => in_array($name, $base, true))))
        ->toBe($base);
})->with(array_keys(memberOrderBases()));

/*
 * And the guard EXECUTED against the rule it replaced: appending is what the two answers exist to avoid,
 * and it really does come out the other way round. Without this the property above would pass for a
 * rule that had quietly become "append".
 */
it('is a rule appending would fail', function (): void {
    $properties = ['id' => [], 'title' => []];

    $counted = array_keys(MemberOrder::intoProperties(
        MemberOrder::intoProperties($properties, 'zebra', []),
        'aardvark',
        [],
    ));

    $appended = array_keys([...$properties, 'zebra' => [], 'aardvark' => []]);

    expect($counted)->toBe(['aardvark', 'id', 'title', 'zebra'])
        ->and($appended)->toBe(['id', 'title', 'zebra', 'aardvark'])
        ->and($counted)->not->toBe($appended);
});
