<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\Pagination\PagesController;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\PaginationEngine;
use Illuminate\Routing\Router;

/**
 * The page-of-X hoist through the whole adapter: every paginator kind, one item type paginated twice,
 * a second item type beside it, the envelope members shared across all of them, and the two shapes that
 * keep an envelope inline. The kind comes from a scripted trace and the item type from a scripted
 * return, so what these rows exercise is the real response path — {@see PageComponentTest} covers the
 * naming table on its own, and {@see PaginationPartsTest} the member hoist on its own.
 *
 * Route URIs all sort after everything the workbench states, so nothing here perturbs it.
 */
beforeEach(function (): void {
    /** @var Router $router */
    $router = app('router');
    foreach (array_keys(PaginationEngine::TERMINALS) as $action) {
        $router->get('api/zz-pages-'.$action, [PagesController::class, $action]);
    }

    app()->instance(TypeEngine::class, PaginationEngine::make());
});

it('publishes one page component per item type and paginator kind', function (string $action, ?string $component): void {
    $document = generateDocument()->document->toArray();
    $schema = $document['paths']['/api/zz-pages-'.$action]['get']['responses']['200']['content']['application/json']['schema'];

    if ($component === null) {
        // Vague but true: the envelope stays on the operation, because nothing could name a page of this
        // item. Its members are still `$ref`s — their shapes never depended on the item type.
        expect(stripDocuccino($schema))->toHaveKeys(['type', 'properties', 'required'])
            ->and($schema)->not->toHaveKey('$ref')
            ->and($schema['required'])->toBe(['data', 'links', 'meta'])
            ->and($schema['properties']['links'])->toHaveKey('$ref')
            ->and($schema['properties']['meta'])->toHaveKey('$ref');

        return;
    }

    expect(stripDocuccino($schema))->toBe(['$ref' => '#/components/schemas/'.$component])
        ->and($document['components']['schemas'])->toHaveKey($component);

    // The envelope references the item's own component rather than inlining a second copy of it.
    $envelope = $document['components']['schemas'][$component];
    expect($envelope['required'])->toBe(['data', 'links', 'meta'])
        ->and($envelope['properties']['data']['items'])->toHaveKey('$ref');
})->with([
    'length-aware' => ['articles', 'ArticleResourcePage'],
    'simple' => ['simpleArticles', 'ArticleResourceSimplePage'],
    'cursor' => ['cursorArticles', 'ArticleResourceCursorPage'],
    'a second item type' => ['authors', 'AuthorResourcePage'],
    // No class to name a page of, and a named class whose schema never became a component: both keep
    // the envelope where it was.
    'an item type that is no class' => ['shapedItems', null],
    'an item class the analyser cannot expand' => ['unexpandable', null],
]);

it('points a page at the components its envelope members name', function (string $action, string $links, string $meta): void {
    $document = generateDocument()->document->toArray();
    $schema = $document['paths']['/api/zz-pages-'.$action]['get']['responses']['200']['content']['application/json']['schema'];
    $page = $document['components']['schemas'][substr((string) $schema['$ref'], strlen('#/components/schemas/'))];

    // Only `data` is restated per item type — OpenAPI has no generics, so it has to be. The members that
    // are a function of the paginator alone are pointers, and the page is a flat object of them: no
    // `allOf`, which generators flatten or turn into an inheritance hierarchy at random.
    expect($page)->not->toHaveKey('allOf')
        ->and($page['properties']['links'])->toBe(['$ref' => '#/components/schemas/'.$links])
        ->and($page['properties']['meta'])->toBe(['$ref' => '#/components/schemas/'.$meta])
        ->and($document['components']['schemas'])->toHaveKeys([$links, $meta]);
})->with([
    // Length-aware and cursor pages carry the same four links, so they name one component between them;
    // their metas differ, so they never share one.
    'length-aware' => ['articles', 'PaginationLinks', 'PaginationMeta'],
    'simple' => ['simpleArticles', 'SimplePaginationLinks', 'SimplePaginationMeta'],
    'cursor' => ['cursorArticles', 'PaginationLinks', 'CursorPaginationMeta'],
]);

it('lands two item types paginated the same way on one set of envelope members', function (): void {
    $document = generateDocument()->document->toArray();
    $schemas = $document['components']['schemas'];

    // The whole point: N item types, one `links` and one `meta` between them. A per-item-type copy would
    // hand an SDK generator N identical meta types beside the N page types it cannot avoid.
    expect($schemas['ArticleResourcePage']['properties']['meta'])
        ->toBe($schemas['AuthorResourcePage']['properties']['meta'])
        ->and($schemas['ArticleResourcePage']['properties']['links'])
        ->toBe($schemas['AuthorResourcePage']['properties']['links'])
        // …and the two data members are the one thing that still differs.
        ->and($schemas['ArticleResourcePage']['properties']['data'])
        ->not->toBe($schemas['AuthorResourcePage']['properties']['data']);

    // Nothing landed on a suffixed member component, which is what a name minted per page would give.
    $suffixed = array_filter(
        array_keys($schemas),
        static fn (string $name): bool => (bool) preg_match('/^(Simple|Cursor)?Pagination(Links|Meta)_/', $name),
    );
    expect($suffixed)->toBe([]);
});

it('lands two operations paginating one item type on the same component', function (): void {
    $document = generateDocument()->document->toArray();

    $first = $document['paths']['/api/zz-pages-articles']['get']['responses']['200']['content']['application/json']['schema'];
    $second = $document['paths']['/api/zz-pages-moreArticles']['get']['responses']['200']['content']['application/json']['schema'];

    expect($first['$ref'])->toBe('#/components/schemas/ArticleResourcePage')
        ->and($second['$ref'])->toBe($first['$ref']);

    // One page type per item type is the point of the hoist — a second one under a suffixed name would
    // hand an SDK generator back the duplicate it exists to prevent.
    $suffixed = array_filter(
        array_keys($document['components']['schemas']),
        static fn (string $name): bool => str_starts_with($name, 'ArticleResourcePage_'),
    );
    expect($suffixed)->toBe([]);
});

it('never takes the name of the item type it is a page of', function (): void {
    $schemas = generateDocument()->document->toArray()['components']['schemas'];

    // The page is a facet of the item's identity, so the two never contested a name and the item keeps
    // the plain one a generated client is already written against.
    expect($schemas)->toHaveKeys(['ArticleResource', 'ArticleResourcePage', 'AuthorResource', 'AuthorResourcePage'])
        // …and the shape under that name is still the item's own, not a page that displaced it.
        ->and(array_keys($schemas['ArticleResource']['properties']))->not->toContain('links')
        ->and(array_keys($schemas['ArticleResourcePage']['properties']))->toBe(['data', 'links', 'meta']);
});

it('serves the page components from a warm cache byte-identically', function (): void {
    fragmentCacheDir('pages');
    $engine = new CountingTypeEngine(PaginationEngine::make());
    app()->instance(TypeEngine::class, $engine);

    // A page component is registered by the route that references it and travels on that route's
    // fragment; two routes sharing one page each carry it, so a warm hit has to put back exactly one.
    // The envelope members are shared wider still — every paginated route reaches them — so a fragment
    // that recorded only what it registered first would come back a member short.
    $cold = (new UirEmitter)->emit(generateDocument()->document);
    expect($engine->analyzeCount)->toBeGreaterThan(0)
        ->and($cold)->toContain('"PaginationLinks"', '"PaginationMeta"', '"CursorPaginationMeta"');

    $engine->analyzeCount = 0;
    $warm = (new UirEmitter)->emit(generateDocument()->document);

    expect($warm)->toBe($cold)
        ->and($engine->analyzeCount)->toBe(0);
});

it('restores the inline envelope byte-for-byte when hoisting is off', function (): void {
    $hoisted = generateDocument()->document->toArray();
    $inline = generateDocument(function (array $raw): array {
        $raw['representation']['pagination']['components'] = false;

        return $raw;
    })->document->toArray();

    $body = static fn (array $document, string $action): array => $document['paths']['/api/zz-pages-'.$action]['get']['responses']['200']['content']['application/json']['schema'];

    // Off, the envelope is on the operation and every component the hoist mints is gone entirely — the
    // page and its members alike, since one switch governs the one decision.
    expect(array_keys($inline['components']['schemas']))
        ->not->toContain('ArticleResourcePage', 'PaginationLinks', 'PaginationMeta')
        ->and(stripDocuccino($body($inline, 'articles'))['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($body($inline, 'articles')['properties']['meta'])->not->toHaveKey('$ref');

    // And the two really are the same envelope, just placed differently: resolving the page component's
    // member pointers gives back the inline document's body, byte for byte.
    $resolved = stripDocuccino($hoisted['components']['schemas']['ArticleResourcePage']);
    foreach (['links', 'meta'] as $member) {
        $name = substr((string) $resolved['properties'][$member]['$ref'], strlen('#/components/schemas/'));
        $resolved['properties'][$member] = stripDocuccino($hoisted['components']['schemas'][$name]);
    }

    expect(stripDocuccino($body($inline, 'articles')))->toBe($resolved);
});
