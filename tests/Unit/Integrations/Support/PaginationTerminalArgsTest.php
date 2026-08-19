<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Support\PaginationTerminalVisitor;
use Docuccino\Laravel\Tests\Support\TraceScript;

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

it('records a spread terminal as paginating, with nothing indexable about its arguments', function (string $chain): void {
    // A spread fills its own position and every later one from a sequence, so `paginate(...$args)` may
    // well have renamed the page key. That the chain paginates is still true; which argument sits where
    // is not, and null is how the readers below say so.
    $visitor = new PaginationTerminalVisitor(PaginationTerminalVisitor::PAGINATOR_TERMINALS);
    TraceScript::forChain($chain, 'Illuminate\\Database\\Eloquent\\Builder')($visitor);

    expect($visitor->paginates)->toBeTrue()
        ->and($visitor->terminal)->toBe('paginate')
        ->and($visitor->kind)->toBe('length')
        ->and($visitor->outermostArgs)->toBeNull();
})->with([
    'every argument spread' => ['$query->paginate(...$args)'],
    // Unpacking a keyed array binds parameters BY name, so a name written past a spread is no more
    // knowable than a position is.
    'a name after a spread' => ["\$query->paginate(...\$args, pageName: 'p')"],
]);

it('reads a spread the call site wrote out, whose items ARE the arguments', function (): void {
    // Nothing is hidden in `...[['*'], 'p']`: the items sit at the positions they take, so declining here
    // would widen away a page key the endpoint really reads.
    $visitor = new PaginationTerminalVisitor(PaginationTerminalVisitor::PAGINATOR_TERMINALS);
    TraceScript::forChain("\$query->paginate(25, ...[['*'], 'p'])", 'Illuminate\\Database\\Eloquent\\Builder')($visitor);

    expect($visitor->outermostArgs)->toBe([0 => 25, 1 => null, 2 => 'p'])
        ->and($visitor->stringArg(2))->toBe('p');
});

it('answers nothing at all when the call carried a spread', function (string|int $key): void {
    // Not "absent", which a consumer reads as the framework's own default — unknown.
    $visitor = new PaginationTerminalVisitor(PaginationTerminalVisitor::PAGINATOR_TERMINALS);
    $visitor->outermostArgs = null;

    expect($visitor->intArg($key))->toBeNull()
        ->and($visitor->stringArg($key))->toBeNull();
})->with([
    'by position' => [0],
    'by name' => ['pageName'],
]);
