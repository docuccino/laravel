<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AuthorData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapAttributeData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapDisabledData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapItemData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapListData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapMapData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapNestedDisabledData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapOwnKeyData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapPaginatedData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapPhantomData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapSelfUnwrappedData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapTransformedData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapUnattributedData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapUnreadKeyData;
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
    // A class that takes its OWN envelope off. Spatie's two switches are not one axis — the object's
    // `Wrap` decides the root and nothing under it — so the collection in here is still wrapped and
    // the report is still owed. The oracle at the bottom is what says so.
    'a class that unwraps only its own root' => [NestedWrapSelfUnwrappedData::class, null],
    // Doubt about the root's KEY is not doubt about whether anything under it is wrapped: a nested
    // collection takes the global key whatever the class named for itself.
    'a class whose own wrap key could not be read' => [NestedWrapUnreadKeyData::class, null],
    // A disabling aimed at a value the class HOLDS. It rides that one transformation and never
    // reaches the ordinary serialisation of anything, so the collection is wrapped exactly as it
    // would be with no disabling in the file at all. The oracle at the bottom is what says so.
    'a class that disables a transformation of a value it holds' => [NestedWrapNestedDisabledData::class, null],
]);

it('stays silent where nothing will be wrapped', function (string $fqcn, ?string $wrap, ?ClassT $collection): void {
    expect(convertNestedWrap($fqcn, $wrap, $collection)['codes'])
        ->not->toContain('spatie-data.nested-collection-wrap');
})->with([
    'no global wrap is configured' => [NestedWrapListData::class, null, null],
    'only the class names a wrap, which a nested collection does not inherit' => [NestedWrapOwnKeyData::class, null, null],
    'the property carries a transformer' => [NestedWrapTransformedData::class, 'data', null],
    'the class disables wrapping outright' => [NestedWrapDisabledData::class, 'data', null],
    // The disabling is here and no receiver can be named for it, so which switch was thrown is
    // unknown and half of them reach down here. Reporting anyway would be a coin flip, and for this
    // class it would land wrong — the oracle at the bottom renders it bare.
    'the disabling could not be attributed to a receiver' => [NestedWrapUnattributedData::class, 'data', null],
    'the collection is paginated, so the schema already carries its envelope' => [
        NestedWrapPaginatedData::class,
        'data',
        new ClassT('Spatie\\LaravelData\\PaginatedDataCollection', [ScalarT::int(), new ClassT(NESTED_WRAP_ITEM)]),
    ],
    // Nothing spatie serialises, so nothing it can wrap. The oracle at the bottom is what says so —
    // reading the absence as "a transformer must be handling it" happened to land on silence here and
    // would report the moment it stopped, which is a claim about a key no response carries.
    'the property exists only in the class docblock' => [NestedWrapPhantomData::class, 'data', null],
]);

it('answers no transformer for a property the class does not declare', function (): void {
    // The two questions the one reading used to collapse. `#[WithTransformer]` is a thing an author
    // WRITES; a property nothing can read carries none, and saying otherwise suppresses whatever the
    // caller was about to publish for a reason that is not in the code.
    $reflector = new DataClassReflector;

    expect($reflector->isPropertyTransformed(NestedWrapPhantomData::class, 'things'))->toBeFalse()
        ->and($reflector->declaresProperty(NestedWrapPhantomData::class, 'things'))->toBeFalse()
        ->and($reflector->declaresProperty(NestedWrapPhantomData::class, 'label'))->toBeTrue()
        ->and($reflector->isPropertyTransformed(NestedWrapTransformedData::class, 'things'))->toBeTrue();
});

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

it('pins that a docblock-only property is not on the wire at all', function (): void {
    // Why silence is right for it, rather than merely convenient: spatie builds its properties from
    // reflection, so the `@property` tag contributes no key to serialise and no key to wrap.
    bootLaravelData('data');

    $rendered = (new NestedWrapPhantomData('a'))->toResponse(request())->getData(true);

    expect($rendered)->toBe(['data' => ['label' => 'a']]);
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

it('pins that a disabling handed to a held value leaves the nested envelope alone', function (): void {
    // The answer to "should a nested transformation's disabling reach the collection?" — no. Spatie
    // only ever reads a `WrapExecutionType` off the context a transformation is handed, and the one
    // built here is handed to the author and to nothing else, so the ordinary serialisation of the
    // root still runs Enabled and still wraps the collection under it.
    bootLaravelData('data');

    $rendered = (new NestedWrapNestedDisabledData([new NestedWrapItemData('a')], new AuthorData('a', 'a@example.com')))
        ->toResponse(request())
        ->getData(true);

    expect($rendered)->toBe([
        'data' => [
            'things' => ['data' => [['label' => 'a']]],
            'author' => ['name' => 'a', 'email' => 'a@example.com'],
        ],
    ]);
});

it('pins that a disabling no receiver could be named for really can take the nested envelope off', function (): void {
    // Why the report is suppressed rather than merely quietened: this class throws the switch that
    // propagates, so everything comes back bare. A report here would name a divergence that is not
    // on the wire, and the read cannot tell this shape from the one that keeps the envelope.
    bootLaravelData('data');

    $rendered = (new NestedWrapUnattributedData([new NestedWrapItemData('a')]))
        ->toResponse(request())
        ->getData(true);

    expect($rendered)->toBe(['things' => [['label' => 'a']]]);
});

it('pins that unwrapping the root leaves the nested envelope exactly where it was', function (): void {
    // The two switches, told apart on the wire. `withoutWrapping()` writes the object's own `Wrap`,
    // which `TransformedDataResolver` reads once for the root; the collection under it is governed by
    // the transformation's `WrapExecutionType`, which nothing here touched. Suppressing the report
    // for this class would leave an author with a `{"data": …}` on the wire and a bare array in the
    // document, and no way to find out.
    bootLaravelData('data');

    $rendered = (new NestedWrapSelfUnwrappedData([new NestedWrapItemData('a')]))
        ->toBareResponse(request())
        ->getData(true);

    expect($rendered)->toBe(['things' => ['data' => [['label' => 'a']]]]);
});
