<?php

declare(strict_types=1);
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * The paginated-collection response extensions end-to-end through the workbench pipeline (Wave C
 * items 1 + 2): a paginate() resource collection and a jsonPaginate() one, plus the withoutWrapping
 * interaction. Detection is scripted by the stub {@see WorkbenchEngine}
 * (the trace reaches the terminal on a plain Eloquent builder); the real trace is proven in
 * RealEngineIntegrationsTest.
 */
beforeEach(function (): void {
    bindStubEngine();
});

it('wraps a paginated resource collection in the length-aware envelope', function (): void {
    $document = generateDocument()->document->toArray();
    $op = $document['paths']['/api/paginated-articles']['get'];

    // The envelope is a shared page component; the operation points at it.
    $schema = resolveSchema($document, $op['responses']['200']['content']['application/json']['schema']);

    // …and the envelope's own members are components too, so the page states only `data` itself.
    $links = resolveSchema($document, $schema['properties']['links']);
    $meta = resolveSchema($document, $schema['properties']['meta']);

    expect($schema['type'])->toBe('object')
        ->and($schema['required'])->toBe(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['items'])->toHaveKey('$ref')
        ->and(array_keys($links['properties']))->toBe(['first', 'last', 'prev', 'next'])
        ->and($meta['properties'])->toHaveKeys(['current_page', 'last_page', 'total']);
});

it('documents how to ask for the next page of a paginated resource collection', function (): void {
    $document = generateDocument()->document->toArray();

    // The endpoint whose body says "page 3 of 12" also says how to ask for page 4 — and claims nothing
    // about a size the framework never reads off the request.
    $paginated = paramsByName($document['paths']['/api/paginated-articles']['get']);
    expect(array_keys($paginated))->toBe(['page'])
        ->and($paginated['page']['schema']['type'])->toBe('integer');

    // An unpaginated collection of the same resource stays parameterless.
    expect($document['paths']['/api/article-resources']['get'])->not->toHaveKey('parameters');
});

it('documents the jsonPaginate response envelope alongside its page params', function (): void {
    $document = generateDocument()->document->toArray();
    $op = $document['paths']['/api/json-paginated-articles']['get'];

    // Same item type and same kind as the paginate() endpoint next door, so it is the same component:
    // one page-of-ArticleResource type in a generated client, not one per producer.
    $schema = $op['responses']['200']['content']['application/json']['schema'];
    expect($schema['$ref'])->toBe($document['paths']['/api/paginated-articles']['get']['responses']['200']['content']['application/json']['schema']['$ref'])
        ->and(resolveSchema($document, $schema)['required'])->toBe(['data', 'links', 'meta']);

    // The package's own vocabulary, and nothing beside it: `jsonPaginate` is not one of the terminals
    // the resource-collection page key is minted for, so no bare `page` joins these.
    $params = paramsByName($op);
    expect(array_keys($params))->toBe(['page[number]', 'page[size]']);
});

it('still data-wraps a paginated collection when resource wrapping is disabled', function (): void {
    $document = generateDocument(function (array $raw): array {
        $raw['integrations']['api_resources']['wrap'] = false;

        return $raw;
    })->document->toArray();

    // A non-paginated collection loses its data wrapper under withoutWrapping (bare array)...
    $plain = $document['paths']['/api/article-resources']['get']['responses']['200']['content']['application/json']['schema'];
    expect($plain['type'])->toBe('array');

    // ...but a paginated one keeps it: the envelope forces `data` even so (bare-array branch).
    $reference = $document['paths']['/api/paginated-articles']['get']['responses']['200']['content']['application/json']['schema'];
    $paginated = resolveSchema($document, $reference);
    expect($paginated['type'])->toBe('object')
        // The `items` the bare-array body left behind came off with everything else the `$ref` replaced.
        ->and($reference)->not->toHaveKey('items')
        ->and($paginated['properties'])->toHaveKey('data')
        ->and($paginated['required'])->toBe(['data', 'links', 'meta']);
});

it('re-homes a created-resource response from 200 to 201', function (): void {
    $op = generateDocument()->document->toArray()['paths']['/api/created-articles']['post'];

    // The resource body moves to 201 Created; the inferred 200 is dropped.
    expect($op['responses'])->toHaveKey('201')
        ->and($op['responses'])->not->toHaveKey('200')
        ->and($op['responses']['201']['description'])->toBe('Created')
        ->and($op['responses']['201']['content']['application/json']['schema']['properties'])->toHaveKey('data');
});
