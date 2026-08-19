<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Support\PaginatorPageParameter;
use Docuccino\Laravel\Integrations\Support\RequestPageSizeKey;

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

it('reads the name argument of every terminal that takes one, positionally or by name', function (string $terminal, string $kind, ?array $args, ?string $name): void {
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
    // A spread makes every position unknown rather than absent, and the terminal that takes a name
    // argument may have been handed one in there — so no key, for the same reason as an unfoldable one.
    'a spread that may hold the name' => ['paginate', 'length', null, null],
    'a spread on a cursor terminal' => ['cursorPaginate', 'cursor', null, null],
    // A custom terminal takes no name argument whatever its own signature holds, so the default stands.
    'a spread on a custom terminal' => ['paginateList', 'length', null, 'page'],
]);

it('keeps the default key when no terminal was recorded at all', function (): void {
    expect(PaginatorPageParameter::forTerminal(null, 'length', [])?->name)->toBe('page');
});

it('mints a page-size selector stating the type and, only where proven, the default', function (?int $default, array $schema): void {
    // No `minimum`/`maximum`: an app clamps an out-of-range size far more often than it rejects one, and a
    // bound recovered from a clamp would call a value invalid that is merely adjusted.
    $spec = PaginatorPageParameter::size(new RequestPageSizeKey('per_page', $default));

    expect($spec->name)->toBe('per_page')
        ->and($spec->schema)->toBe($schema)
        ->and($spec->description)->toBe('Number of items per page.')
        // A page size is never required — omitting it is what asks for the endpoint's own default.
        ->and($spec->style)->toBeNull();
})->with([
    'a proven default' => [15, ['type' => 'integer', 'default' => 15]],
    'no honest default' => [null, ['type' => 'integer']],
]);

it('mints the size selector under whatever key the app reads, not a spelling of its own', function (): void {
    expect(PaginatorPageParameter::size(new RequestPageSizeKey('limit'))->name)->toBe('limit');
});
