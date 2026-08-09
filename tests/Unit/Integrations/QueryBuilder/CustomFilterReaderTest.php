<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\QueryBuilder\CustomFilterReader;
use Workbench\App\Filters\CompositeFilter;
use Workbench\App\Filters\DocumentedFilter;
use Workbench\App\Filters\ScoreFilter;

/**
 * The custom-filter facts reader: a class-level `#[QueryParameter]` attribute wins (and suppresses
 * body inference), else the single column its `__invoke` filters on is recovered, else nothing —
 * always exposing the declaring file for cache soundness.
 */
it('recovers the where column from a __invoke body', function (): void {
    $facts = (new CustomFilterReader)->read(ScoreFilter::class);

    expect($facts->column)->toBe('score')
        ->and($facts->attribute)->toBeNull()
        ->and(basename((string) $facts->file))->toBe('ScoreFilter.php');
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
        ->and($facts->file)->not->toBeNull();
});

it('degrades an unknown filter class to empty facts', function (): void {
    $facts = (new CustomFilterReader)->read('App\\Filters\\Nope');

    expect($facts->file)->toBeNull()
        ->and($facts->attribute)->toBeNull()
        ->and($facts->column)->toBeNull();
});
