<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Support\PaginatorPageParameter;

/**
 * The one place a Laravel page selector is minted, pinned per kind — including the kind it has never
 * heard of, which degrades to the length-aware key exactly as the envelope builder does.
 */
it('mints the key each paginator kind reads', function (?string $kind, string $name, array $schema, string $description): void {
    $spec = PaginatorPageParameter::for($kind);

    expect($spec->name)->toBe($name)
        ->and($spec->schema)->toBe($schema)
        ->and($spec->description)->toBe($description)
        ->and($spec->style)->toBeNull()
        ->and($spec->explode)->toBeNull()
        ->and($spec->example)->toBeNull();
})->with([
    'length' => ['length', 'page', ['type' => 'integer', 'default' => 1, 'minimum' => 1], 'Page number.'],
    'simple' => ['simple', 'page', ['type' => 'integer', 'default' => 1, 'minimum' => 1], 'Page number.'],
    'cursor' => ['cursor', 'cursor', ['type' => 'string'], 'Opaque cursor for the next/previous page.'],
    'an unknown kind' => ['weekly', 'page', ['type' => 'integer', 'default' => 1, 'minimum' => 1], 'Page number.'],
    'no kind at all' => [null, 'page', ['type' => 'integer', 'default' => 1, 'minimum' => 1], 'Page number.'],
]);

it('carries the name the call site chose instead of the default', function (?string $kind, string $name): void {
    expect(PaginatorPageParameter::for($kind, $name)->name)->toBe($name);
})->with([
    'a renamed page' => ['length', 'p'],
    'a renamed cursor' => ['cursor', 'after'],
]);

it('reads the name argument of every terminal that takes one, positionally or by name', function (string $terminal, string $kind, array $args, ?string $name): void {
    $spec = PaginatorPageParameter::forTerminal($terminal, $kind, $args);

    expect($spec?->name)->toBe($name);
})->with([
    // paginate($perPage, $columns, $pageName) / cursorPaginate($perPage, $columns, $cursorName).
    'paginate, unnamed' => ['paginate', 'length', [0 => 20], 'page'],
    'paginate, positional' => ['paginate', 'length', [0 => 20, 1 => null, 2 => 'p'], 'p'],
    'paginate, by name' => ['paginate', 'length', ['perPage' => 20, 'pageName' => 'p'], 'p'],
    'simplePaginate, unnamed' => ['simplePaginate', 'simple', [], 'page'],
    'simplePaginate, positional' => ['simplePaginate', 'simple', [0 => 20, 1 => null, 2 => 'p'], 'p'],
    'simplePaginate, by name' => ['simplePaginate', 'simple', ['pageName' => 'p'], 'p'],
    'cursorPaginate, unnamed' => ['cursorPaginate', 'cursor', [0 => 20], 'cursor'],
    'cursorPaginate, positional' => ['cursorPaginate', 'cursor', [0 => 20, 1 => null, 2 => 'after'], 'after'],
    'cursorPaginate, by name' => ['cursorPaginate', 'cursor', ['cursorName' => 'after'], 'after'],
    // A terminal outside the table takes no name argument of its own — it forwards to `paginate($perPage)`,
    // so the default key stands however many arguments its own signature has.
    'a custom terminal' => ['paginateList', 'length', [0 => 25, 1 => 'anything', 2 => 'else'], 'page'],
    // Written but unfoldable: a guessed `page` would name a key the endpoint does not read.
    'an unfoldable positional name' => ['paginate', 'length', [0 => 20, 1 => null, 2 => null], null],
    'an unfoldable named name' => ['cursorPaginate', 'cursor', ['cursorName' => null], null],
    'a name that folded to the empty string' => ['paginate', 'length', [2 => ''], null],
]);

it('keeps the default key when no terminal was recorded at all', function (): void {
    expect(PaginatorPageParameter::forTerminal(null, 'length', [])?->name)->toBe('page');
});
