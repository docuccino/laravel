<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\QueryBuilder\CustomFilterReader;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\NamelessFilter;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\ScoreBandFilter;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\SlugFilter;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\TitleFilter;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\UnreadableFilter;
use Workbench\App\Filters\CompositeFilter;
use Workbench\App\Filters\DocumentedFilter;
use Workbench\App\Filters\ScoreFilter;

/**
 * The custom-filter facts reader: a class-level `#[QueryParameter]` attribute wins (and suppresses
 * body inference), else the single column its `__invoke` filters on is recovered, else nothing —
 * always exposing the files its declaration spans for cache soundness.
 */
it('recovers the where column from a __invoke body', function (): void {
    $facts = (new CustomFilterReader)->read(ScoreFilter::class);

    expect($facts->column)->toBe('score')
        ->and($facts->attribute)->toBeNull()
        ->and(array_map(basename(...), $facts->files))->toBe(['ScoreFilter.php']);
});

it('prefers a class-level QueryParameter attribute over body inference', function (): void {
    $facts = (new CustomFilterReader)->read(DocumentedFilter::class);

    expect($facts->attribute)->not->toBeNull()
        ->and($facts->attribute?->type)->toBe('int')
        ->and($facts->attribute?->description)->toBe('Minimum popularity score.')
        ->and($facts->attribute?->example)->toBe(42)
        // The attribute is the override — the opaque body is not consulted.
        ->and($facts->column)->toBeNull();
});

it('bails to no column for a complex __invoke body', function (): void {
    $facts = (new CustomFilterReader)->read(CompositeFilter::class);

    expect($facts->column)->toBeNull()
        ->and($facts->attribute)->toBeNull()
        ->and($facts->files)->not->toBe([]);
});

it('degrades an unknown filter class to empty facts', function (): void {
    $facts = (new CustomFilterReader)->read('App\\Filters\\Nope');

    expect($facts->files)->toBe([])
        ->and($facts->attribute)->toBeNull()
        ->and($facts->column)->toBeNull();
});

it('reads the __invoke PHP calls on the filter, not the last one its file writes', function (string $filter, string $column): void {
    // Three filters share a file on three columns. A body found by name alone answers every one with the
    // file's last `__invoke`, typing the filter off a column it never touches.
    class_exists(TitleFilter::class); // the file declaring all three
    expect((new CustomFilterReader)->read($filter)->column)->toBe($column);
})->with([
    'a filter a sibling follows in its file' => [TitleFilter::class, 'title'],
    'a filter taking its __invoke from a trait under an alias' => [SlugFilter::class, 'slug'],
    'the file\'s last filter' => [ScoreBandFilter::class, 'score'],
]);

it('reads an attribute that leaves out the name the filter registration supplies', function (): void {
    // The parameter's name is the `AllowedFilter` one, so a class-level declaration has nothing to name;
    // requiring it anyway made the documented form an attribute PHP refused to construct.
    $facts = (new CustomFilterReader)->read(NamelessFilter::class);

    expect($facts->attribute?->name)->toBeNull()
        ->and($facts->attribute?->type)->toBe('int')
        ->and($facts->attribute?->description)->toBe('Minimum band.')
        ->and($facts->diagnostics)->toBe([]);
});

it('reports an attribute PHP cannot construct, and reads the body as if it were not there', function (): void {
    // The author wrote a declaration and it took no effect, so they are told where — the class and what
    // was thrown, never the thrown message with its absolute path. What is left is what an unannotated
    // class gets, so the answer stays true.
    $facts = (new CustomFilterReader)->read(UnreadableFilter::class, 'GET /api/gadgets');

    expect($facts->attribute)->toBeNull()
        ->and($facts->column)->toBe('score')
        ->and(array_map(static fn ($d): array => [$d->code, $d->message, $d->help, $d->routeSignature], $facts->diagnostics))->toBe([[
            'attribute.unreadable',
            'The #[QueryParameter] on '.UnreadableFilter::class.' could not be instantiated and was ignored.',
            'Its constructor threw TypeError. Check the arguments at that declaration against the attribute\'s constructor.',
            'GET /api/gadgets',
        ]]);
});

it('keys the files it read even where the attribute cannot be built', function (): void {
    // A cold build gets no attribute — and a warm one must still re-read once the author fixes it.
    // Dropping the files with the facts left the fragment keyed on nothing.
    $facts = (new CustomFilterReader)->read(UnreadableFilter::class);

    expect(array_map(basename(...), $facts->files))->toBe(['UnreadableFilter.php']);
});
