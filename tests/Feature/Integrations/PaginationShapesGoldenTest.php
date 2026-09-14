<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\Pagination\PagesController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AuthorData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\PaginatedCollectionController;
use Docuccino\Laravel\Tests\Support\PaginationEngine;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * Every paginated shape the product publishes that the rest of the golden corpus does not carry: the
 * simple and cursor pages of Laravel's envelope, and both of spatie's. The committed goldens otherwise
 * hold a length-aware resource page and nothing else, so most of the envelope member components and
 * every page kind but one could change in any way at all with every byte in the corpus standing still.
 *
 * One route each, its own document, so no existing golden moves and this one moves only when what
 * these shapes publish does.
 */
$engine = static fn (): TypeEngine => WorkbenchEngine::make(
    classOverrides: [
        AuthorData::class => new ClassMetadata(AuthorData::class, [
            new PropertyMetadata('name', ScalarT::string()),
            new PropertyMetadata('email', ScalarT::string()),
        ]),
    ],
    analysisOverrides: [
        PagesController::class.'::simpleArticles' => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection', [new ClassT(PaginationEngine::ARTICLE_RESOURCE)]),
            new SourceLocation(''),
        )]),
        PagesController::class.'::cursorArticles' => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection', [new ClassT(PaginationEngine::ARTICLE_RESOURCE)]),
            new SourceLocation(''),
        )]),
        PaginatedCollectionController::class.'::index' => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Spatie\\LaravelData\\PaginatedDataCollection', [ScalarT::int(), new ClassT(AuthorData::class)]),
            new SourceLocation(''),
        )]),
        PaginatedCollectionController::class.'::feed' => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Spatie\\LaravelData\\CursorPaginatedDataCollection', [ScalarT::int(), new ClassT(AuthorData::class)]),
            new SourceLocation(''),
        )]),
    ],
    traceOverrides: [
        PagesController::class.'::simpleArticles' => TraceScript::forChain('$q->simplePaginate(15)', 'Illuminate\\Database\\Eloquent\\Builder'),
        PagesController::class.'::cursorArticles' => TraceScript::forChain('$q->cursorPaginate(15)', 'Illuminate\\Database\\Eloquent\\Builder'),
    ],
);

it('emits every paginated shape byte-identically to its committed golden', function () use ($engine): void {
    bootLaravelData('data');

    $result = localityBuild(static function (Router $router): void {
        $router->get('api/zz-simple-articles', [PagesController::class, 'simpleArticles']);
        $router->get('api/zz-cursor-articles', [PagesController::class, 'cursorArticles']);
        $router->get('api/zz-data-authors', [PaginatedCollectionController::class, 'index']);
        $router->get('api/zz-data-author-feed', [PaginatedCollectionController::class, 'feed']);
    }, $engine);

    assertGolden('pagination-shapes.uir.json', (new UirEmitter)->emit($result->document));

    // What the golden is for, said out loud. These components have no class behind them — nothing an
    // application wrote, nothing an author could annotate — so whether a reader of the document can
    // tell what one holds is decided entirely here.
    $schemas = $result->document->toArray()['components']['schemas'];

    // Listed rather than derived: the point is WHICH components four routes put in a document, which is
    // not a thing the builders can be asked. That every member either builder mints has a sentence at
    // all is the separate guard in PaginationPartsTest, read off the builders themselves.
    $minted = [
        'SimplePaginationLinks',
        'SimplePaginationMeta',
        'CursorPaginationMeta',
        'PaginationLinks',
        'PaginationLink',
        'DataPaginationMeta',
        'DataCursorPaginationMeta',
        'ArticleResourceSimplePage',
        'ArticleResourceCursorPage',
        'AuthorDataPage',
        'AuthorDataCursorPage',
    ];

    expect(array_keys($schemas))->toContain(...$minted);

    foreach ($minted as $name) {
        expect($schemas[$name]['description'] ?? null)
            ->toBeString($name.' says nothing about itself')
            ->not->toBe('', $name.' says nothing about itself');
    }

    // The item types beside them are the control: those DO have a class behind them, so what the
    // document says about one is the author's to write and this change never puts words there.
    expect($schemas['AuthorData'])->not->toHaveKey('description')
        ->and($schemas['ArticleResource'])->not->toHaveKey('description');
});
