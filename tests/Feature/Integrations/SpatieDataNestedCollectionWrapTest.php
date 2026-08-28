<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapAttributeData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapDisabledData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapItemData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapListData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapMapData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapOwnKeyData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapPaginatedData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapTransformedData;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\LaravelData\PaginatedDataCollection;

/*
 * spatie unwraps a nested single Data object and re-wraps a nested COLLECTION. The document keeps the
 * bare array on purpose, so the divergence is reported instead of modelled — these prove where that
 * report fires, and the oracles at the bottom prove the vendor behaviour it all rests on.
 */

const NESTED_WRAP_ITEM = NestedWrapItemData::class;

it('says a nested collection will be wrapped, in every spelling one is written', function (string $fqcn, ?ClassT $collection): void {
    $things = $collection === null
        ? null
        : ($collection->fqcn === MapT::class ? new MapT(ScalarT::string(), new ClassT(NESTED_WRAP_ITEM)) : $collection);

    $result = convertNestedWrap($fqcn, 'data', $things);

    expect($result['codes'])->toContain('spatie-data.nested-collection-wrap');

    $diagnostic = $result['diagnostics']['spatie-data.nested-collection-wrap'];

    expect($diagnostic->severity)->toBe(Severity::Warning)
        ->and($diagnostic->message)->toContain('$things')
        ->and($diagnostic->message)->toContain(NESTED_WRAP_ITEM)
        ->and($diagnostic->message)->toContain('{"data": [ … ]}')
        ->and($diagnostic->help)->toContain('overlay');
})->with([
    'a plain array with a recovered generic' => [NestedWrapListData::class, null],
    'a #[DataCollectionOf] attribute with no generic' => [NestedWrapAttributeData::class, new ClassT(DataClassReflector::DATA_COLLECTION)],
    'a DataCollection carrying its generic' => [NestedWrapListData::class, new ClassT(DataClassReflector::DATA_COLLECTION, [ScalarT::int(), new ClassT(NESTED_WRAP_ITEM)])],
    'a collection keyed by string' => [NestedWrapMapData::class, new ClassT(MapT::class)],
]);

it('stays silent where nothing will be wrapped', function (string $fqcn, ?string $wrap, ?ClassT $collection): void {
    expect(convertNestedWrap($fqcn, $wrap, $collection)['codes'])
        ->not->toContain('spatie-data.nested-collection-wrap');
})->with([
    'no global wrap is configured' => [NestedWrapListData::class, null, null],
    'only the class names a wrap, which a nested collection does not inherit' => [NestedWrapOwnKeyData::class, null, null],
    'the property carries a transformer' => [NestedWrapTransformedData::class, 'data', null],
    'the class disables wrapping outright' => [NestedWrapDisabledData::class, 'data', null],
    'the collection is paginated, so the schema already carries its envelope' => [
        NestedWrapPaginatedData::class,
        'data',
        new ClassT('Spatie\\LaravelData\\PaginatedDataCollection', [ScalarT::int(), new ClassT(NESTED_WRAP_ITEM)]),
    ],
]);

it('leaves a transformed property its declared shape', function (): void {
    expect(convertNestedWrap(NestedWrapTransformedData::class, 'data')['schema']['properties']['things'])
        ->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/NestedWrapItemData']]);
});

// The oracles. Nothing else in the suite compares what the integration believes about spatie to what
// spatie does, which is how the wrapped nested collection went unnoticed.

it('pins that laravel-data really does wrap a nested collection in a response', function (): void {
    bootLaravelData('data');

    $rendered = (new NestedWrapListData([new NestedWrapItemData('a')]))
        ->toResponse(request())
        ->getData(true);

    expect($rendered)->toBe(['data' => ['things' => ['data' => [['label' => 'a']]]]]);
});

it('pins that a keyed collection is wrapped exactly as a list is', function (): void {
    bootLaravelData('data');

    $rendered = (new NestedWrapMapData(['k' => new NestedWrapItemData('a')]))
        ->toResponse(request())
        ->getData(true);

    expect($rendered)->toBe(['data' => ['things' => ['data' => ['k' => ['label' => 'a']]]]]);
});

it('pins that a paginated collection carries the envelope the schema already publishes', function (): void {
    bootLaravelData('data');

    $page = new LengthAwarePaginator([new NestedWrapItemData('a')], 1, 15, 1, ['path' => 'http://localhost']);
    $rendered = (new NestedWrapPaginatedData(
        NestedWrapItemData::collect($page, PaginatedDataCollection::class),
    ))->toResponse(request())->getData(true);

    expect($rendered['data']['things'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($rendered['data']['things']['data'])->toBe([['label' => 'a']]);
});

it('pins the two ways a nested collection comes back bare', function (): void {
    bootLaravelData('data');

    $transformed = (new NestedWrapTransformedData([new NestedWrapItemData('a')]))
        ->toResponse(request())
        ->getData(true);

    $disabled = (new NestedWrapDisabledData([new NestedWrapItemData('a')]))
        ->toResponse(request())
        ->getData(true);

    expect($transformed)->toBe(['data' => ['things' => [['label' => 'a']]]])
        ->and($disabled)->toBe(['things' => [['label' => 'a']]]);
});

it('pins that a class wrap does not reach a nested collection, which is why the global key is named', function (): void {
    bootLaravelData(null);

    $rendered = (new NestedWrapOwnKeyData([new NestedWrapItemData('a')]))
        ->toResponse(request())
        ->getData(true);

    expect($rendered)->toBe(['record' => ['things' => [['label' => 'a']]]]);
});

it('names the global wrap key, which is the one spatie puts a nested collection under', function (): void {
    bootLaravelData('envelope');

    $rendered = (new NestedWrapListData([new NestedWrapItemData('a')]))
        ->toResponse(request())
        ->getData(true);

    expect($rendered['envelope']['things'])->toHaveKey('envelope')
        ->and(convertNestedWrap(NestedWrapListData::class, 'envelope')['diagnostics']['spatie-data.nested-collection-wrap']->message)
        ->toContain('{"envelope": [ … ]}');
});
