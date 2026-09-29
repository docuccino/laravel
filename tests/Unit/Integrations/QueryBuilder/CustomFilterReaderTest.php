<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\QueryBuilder\CustomFilterReader;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\NamelessFilter;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\ScoreBandFilter;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\SlugFilter;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\TitleFilter;
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

it('keys the files it read even where reading the class throws', function (): void {
    // The attribute cannot be built, so a cold build gets no facts — and a warm one must still re-read
    // once the author fixes it. Dropping the files with the facts left the fragment keyed on nothing.
    $facts = (new CustomFilterReader)->read(NamelessFilter::class);

    expect($facts->attribute)->toBeNull()
        ->and($facts->column)->toBeNull()
        ->and(array_map(basename(...), $facts->files))->toBe(['NamelessFilter.php']);
});
