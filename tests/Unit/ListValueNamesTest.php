<?php

declare(strict_types=1);

use Docuccino\Laravel\Support\ListValueNames;

/**
 * Dataset coverage over the SDK member-name minting: every shape a sort/include value takes, the
 * collision tiers (pretty → strict spelling → content suffix), and the guarantees the emission leans
 * on — full-length arrays, names as a pure function of the value set, never a first-come counter.
 */
it('mints an identifier-safe member name from each value shape', function (string $value, string $name): void {
    expect(ListValueNames::names([$value]))->toBe([$name]);
})->with([
    'plain' => ['total', 'Total'],
    'descending' => ['-total', 'TotalDesc'],
    'snake' => ['issued_at', 'IssuedAt'],
    'descending snake' => ['-issued_at', 'IssuedAtDesc'],
    'dotted path' => ['friends.pact', 'FriendsPact'],
    'camel kept' => ['vaultKeeper', 'VaultKeeper'],
    'minted count form' => ['friendsCount', 'FriendsCount'],
    'dashed' => ['first-name', 'FirstName'],
    'leading digit' => ['2fa', '_2fa'],
    'empty base' => ['-', 'ValueDesc'],
]);

it('re-mints colliding values with a strict spelling that keeps the raw distinction', function (): void {
    $values = ['alpha.beta', 'alphaBeta', 'gamma'];

    expect(ListValueNames::names($values))->toBe(['AlphaDotBeta', 'AlphaBeta', 'Gamma'])
        ->and(ListValueNames::collisions($values))->toBe(['alpha.beta', 'alphaBeta']);
});

it('keeps strict-spelled names distinct with a content suffix in the last resort', function (): void {
    // Same pretty name AND same strict spelling can only mean case-identical first letters — the
    // content suffix is a function of each value alone, so neither ever renames the other.
    $names = ListValueNames::names(['Alpha', 'alpha']);

    expect($names)->toHaveCount(2)
        ->and($names[0])->not->toBe($names[1])
        ->and($names[0])->toStartWith('Alpha_')
        ->and($names[1])->toStartWith('Alpha_');
});

it('never renames an uncontested neighbour when a colliding value arrives', function (): void {
    $before = ListValueNames::names(['total', '-total']);
    $after = ListValueNames::names(['total', '-total', 'friends.pact', 'friendsPact']);

    expect(array_slice($after, 0, 2))->toBe($before);
});

it('reports no collisions for a clean value set', function (): void {
    expect(ListValueNames::collisions(['total', '-total', 'friends.pact']))->toBe([]);
});
