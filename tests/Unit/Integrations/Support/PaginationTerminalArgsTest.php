<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Support\PaginationTerminalVisitor;

/**
 * The visitor's read surface over the outermost terminal call's folded arguments. Each consumer reads
 * whichever position or name its own terminal's signature gives the argument it wants, and an argument
 * written but unfoldable is recorded as null — so "absent" and "unresolvable" both answer null here, and
 * are told apart by `array_key_exists` at the call site.
 */
it('answers an int argument by position or by parameter name', function (string|int $key, ?int $expected): void {
    $visitor = new PaginationTerminalVisitor(PaginationTerminalVisitor::PAGINATOR_TERMINALS);
    $visitor->outermostArgs = [0 => 100, 'defaultSize' => 25, 1 => 'not a number'];

    expect($visitor->intArg($key))->toBe($expected);
})->with([
    'a positional int' => [0, 100],
    'a named int' => ['defaultSize', 25],
    'a position holding something else' => [1, null],
    'a position nobody wrote' => [2, null],
    'a name nobody wrote' => ['maxResults', null],
]);

it('answers a string argument by position or by parameter name', function (string|int $key, ?string $expected): void {
    $visitor = new PaginationTerminalVisitor(PaginationTerminalVisitor::PAGINATOR_TERMINALS);
    $visitor->outermostArgs = [2 => 'p', 'cursorName' => 'after', 3 => '', 4 => 15];

    expect($visitor->stringArg($key))->toBe($expected);
})->with([
    'a positional string' => [2, 'p'],
    'a named string' => ['cursorName', 'after'],
    // An empty key names nothing, so it reads the same as one that was never written.
    'a string that folded to nothing' => [3, null],
    'a position holding something else' => [4, null],
    'a position nobody wrote' => [5, null],
    'a name nobody wrote' => ['pageName', null],
]);
