<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\ApiResources\JsonResourceSchema;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Integrations\Support\PaginationEnvelope;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ConditionalMetaResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ConditionalWithResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\DynamicMetaResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ForwardedDataResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\MergedDataResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\MergingWithResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\PartlyDynamicMetaResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseFeedCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\StrayCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\UnwrappedEnvelopedResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\WithPropertyResource;

/**
 * Laravel's `ResourceResponse::wrap()` merges what the ROOT resource's `with()` returns beside its
 * wrapped data, so a response built from a resource with `with()` carries those members every time —
 * and a resource nested in another's `toArray` is never asked for them. Stub-engine mechanics; the
 * real-engine fold of a `with()` body is in the fixture group.
 */
beforeEach(function (): void {
    $loc = new SourceLocation('');
    $sites = static fn (array ...$sites): ActionAnalysis => new ActionAnalysis(
        returns: array_map(static fn (array $fields): ReturnSite => new ReturnSite(new ArrayShapeT($fields), $loc), $sites),
    );
    $tag = [new ArrayShapeField('tag', ScalarT::string())];
    $envelopeMembers = [
        new ArrayShapeField('meta', new ClassT('stdClass')),
        new ArrayShapeField('version', ScalarT::string()),
    ];

    $engine = new StubTypeEngine(analyses: [
        ReleaseResource::class.'::toArray' => $sites($tag),
        ReleaseResource::class.'::with' => $sites($envelopeMembers),
        UnwrappedEnvelopedResource::class.'::toArray' => $sites($tag),
        UnwrappedEnvelopedResource::class.'::with' => $sites($envelopeMembers),
        ConditionalMetaResource::class.'::toArray' => $sites($tag),
        // One branch adds members, the other returns `[]` — an empty shape, which reads as a list.
        ConditionalMetaResource::class.'::with' => $sites(
            [
                new ArrayShapeField('debug', new ArrayShapeT([new ArrayShapeField('query_count', ScalarT::int())])),
                new ArrayShapeField('data', new ArrayShapeT([new ArrayShapeField('traced', ScalarT::bool())])),
            ],
            [],
        ),
        DynamicMetaResource::class.'::toArray' => $sites($tag),
        // `$request->only(...)` has no constant shape: an `array<string, mixed>`.
        DynamicMetaResource::class.'::with' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Support\\Collection'), $loc)]),
        MergedDataResource::class.'::toArray' => $sites($tag),
        MergedDataResource::class.'::with' => $sites([new ArrayShapeField('data', new ArrayShapeT([new ArrayShapeField('traced', ScalarT::bool())]))]),
        PartlyDynamicMetaResource::class.'::toArray' => $sites($tag),
        PartlyDynamicMetaResource::class.'::with' => new ActionAnalysis(returns: [
            new ReturnSite(new ArrayShapeT([new ArrayShapeField('meta', new ClassT('stdClass'))]), $loc),
            new ReturnSite(new ClassT('Illuminate\\Support\\Collection'), $loc),
        ]),
        ConditionalWithResource::class.'::toArray' => $sites($tag),
        ConditionalWithResource::class.'::with' => $sites([
            new ArrayShapeField('debug', UnionT::of([ScalarT::string(), new ClassT(ResourceReflector::MISSING_VALUE)])),
        ]),
        MergingWithResource::class.'::toArray' => $sites($tag),
        MergingWithResource::class.'::with' => $sites([
            new ArrayShapeField('version', ScalarT::string()),
            new ArrayShapeField(0, new ClassT('Illuminate\\Http\\Resources\\MergeValue', [new ArrayShapeT([new ArrayShapeField('debug', ScalarT::string())])])),
        ]),
        WithPropertyResource::class.'::toArray' => $sites($tag),
        // A resource nesting one whose parent adds members — the nested one is never asked for them.
        ArticleResource::class.'::toArray' => $sites([new ArrayShapeField('release', new ClassT(ReleaseResource::class))]),
    ]);

    $this->convert = static fn (ClassT $type, ?ComponentRegistry $components = null, ?RepresentationPolicy $policy = null): array => (new SchemaConverter(
        [new JsonResourceSchema, ...DefaultTypeMappers::all()],
        $engine,
        $components ?? new ComponentRegistry,
        $policy ?? new RepresentationPolicy,
    ))->toSchema($type)->schema;
});

it('publishes the members a root resource inherits from with() beside its data, all required', function (): void {
    $components = new ComponentRegistry;

    expect(($this->convert)(new ClassT(ReleaseResource::class), $components))->toBe([
        'type' => 'object',
        'properties' => [
            'data' => ['$ref' => '#/components/schemas/ReleaseResource'],
            'meta' => ['type' => 'object'],
            'version' => ['type' => 'string'],
        ],
        'required' => ['data', 'meta', 'version'],
    ])
        // The members are the response's, not the resource's: its component carries toArray alone.
        ->and($components->schemas()['ReleaseResource']['properties'])->toBe(['tag' => ['type' => 'string']]);
});

it('publishes no with() members for a resource nested inside another', function (): void {
    $components = new ComponentRegistry;

    expect(($this->convert)(new ClassT(ArticleResource::class), $components))->toBe([
        'type' => 'object',
        'properties' => ['data' => ['$ref' => '#/components/schemas/ArticleResource']],
        'required' => ['data'],
    ])
        ->and($components->schemas()['ArticleResource']['properties'])
        ->toBe(['release' => ['$ref' => '#/components/schemas/ReleaseResource']]);
});

it('wraps an unwrapped resource under data once with() adds members, as Laravel does', function (?string $wrap): void {
    $policy = new RepresentationPolicy(resourceWrap: $wrap ?? '');
    $schema = ($this->convert)(new ClassT($wrap === null ? UnwrappedEnvelopedResource::class : ReleaseResource::class), null, $policy);

    expect($schema['properties'] ?? null)->toHaveKeys(['data', 'meta', 'version'])
        ->and($schema['required'] ?? null)->toBe(['data', 'meta', 'version']);
})->with([
    'the resource declares $wrap = null' => [null],
    'the document disables wrapping' => [RepresentationPolicy::WRAP_DISABLED],
]);

it('publishes the bare and the wrapped body when with() may add nothing, and never a with() key named for the wrapper', function (): void {
    $schema = ($this->convert)(new ClassT(ConditionalMetaResource::class));

    // `data` from with() merges INTO the data; `debug` stands beside it only when with() returned it.
    expect($schema)->toBe(['anyOf' => [
        ['$ref' => '#/components/schemas/ConditionalMetaResource'],
        [
            'type' => 'object',
            'properties' => [
                'data' => ['$ref' => '#/components/schemas/ConditionalMetaResource'],
                'debug' => ['type' => 'object', 'properties' => ['query_count' => ['type' => 'integer']], 'required' => ['query_count']],
            ],
            'required' => ['data'],
        ],
    ]]);
});

it('marks a with() member optional under a wrap key when with() may return nothing', function (): void {
    $schema = ($this->convert)(new ClassT(ConditionalMetaResource::class), null, new RepresentationPolicy(resourceWrap: 'records'));

    expect($schema['required'])->toBe(['records'])
        ->and(array_keys($schema['properties']))->toBe(['records', 'debug', 'data']);
});

it('keeps the envelope open and names only its data when with() has no readable shape', function (): void {
    expect(($this->convert)(new ClassT(DynamicMetaResource::class)))->toBe([
        'type' => 'object',
        'properties' => ['data' => ['$ref' => '#/components/schemas/DynamicMetaResource']],
        'required' => ['data'],
    ]);
});

it('adds nothing for Laravel\'s own with(), which returns the $with property, empty by default', function (): void {
    $collection = new ClassT(ResourceReflector::ANONYMOUS_COLLECTION, [new ClassT(ReleaseResource::class)]);

    // A collection's envelope is the COLLECTION's with(), never its item's.
    expect(($this->convert)($collection))->toBe([
        'type' => 'object',
        'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ReleaseResource']]],
        'required' => ['data'],
    ]);
});

it('publishes an unwrapped resource bare or wrapped when its with() cannot be read, since either may be sent', function (): void {
    $schema = ($this->convert)(new ClassT(DynamicMetaResource::class), null, new RepresentationPolicy(resourceWrap: RepresentationPolicy::WRAP_DISABLED));

    expect($schema)->toBe(['anyOf' => [
        ['$ref' => '#/components/schemas/DynamicMetaResource'],
        [
            'type' => 'object',
            'properties' => ['data' => ['$ref' => '#/components/schemas/DynamicMetaResource']],
            'required' => ['data'],
        ],
    ]]);
});

it('marks no with() member required when a with() branch cannot be read', function (): void {
    $schema = ($this->convert)(new ClassT(PartlyDynamicMetaResource::class));

    expect($schema)->toBe([
        'type' => 'object',
        'properties' => [
            'data' => ['$ref' => '#/components/schemas/PartlyDynamicMetaResource'],
            'meta' => ['type' => 'object'],
        ],
        'required' => ['data'],
    ]);
});

it('wraps an unwrapped resource whose with() returns only the wrap key, which merges into the data', function (): void {
    expect(($this->convert)(new ClassT(MergedDataResource::class)))->toBe([
        'type' => 'object',
        'properties' => ['data' => ['$ref' => '#/components/schemas/MergedDataResource']],
        'required' => ['data'],
    ]);
});

it('does not wrap a body that already carries its wrap key, and offers both where only some branches do', function (): void {
    $loc = new SourceLocation('');
    $list = new ArrayShapeField('data', new ArrayShapeT([new ArrayShapeField('tag', ScalarT::string())]));
    $engine = new StubTypeEngine(analyses: [
        // Always carries `data`: ResourceResponse leaves it unwrapped and merges with()'s members beside.
        ReleaseResource::class.'::toArray' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([$list]), $loc)]),
        ReleaseResource::class.'::with' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([new ArrayShapeField('version', ScalarT::string())]), $loc)]),
        // Carries `data` on one branch only.
        ArticleResource::class.'::toArray' => new ActionAnalysis(returns: [
            new ReturnSite(new ArrayShapeT([$list]), $loc),
            new ReturnSite(new ArrayShapeT([new ArrayShapeField('tag', ScalarT::string())]), $loc),
        ]),
    ]);
    $convert = static fn (string $fqcn): array => (new SchemaConverter([new JsonResourceSchema, ...DefaultTypeMappers::all()], $engine, new ComponentRegistry))
        ->toSchema(new ClassT($fqcn))->schema;

    expect($convert(ReleaseResource::class))->toBe(['allOf' => [
        ['$ref' => '#/components/schemas/ReleaseResource'],
        ['type' => 'object', 'properties' => ['version' => ['type' => 'string']], 'required' => ['version']],
    ]])
        ->and($convert(ArticleResource::class))->toBe(['anyOf' => [
            [
                'type' => 'object',
                'properties' => ['data' => ['$ref' => '#/components/schemas/ArticleResource']],
                'required' => ['data'],
            ],
            ['$ref' => '#/components/schemas/ArticleResource'],
        ]]);
});

it('publishes a carried data key a with() data key is merged into as what the merge sends', function (array $toArray, array $with, array $expected): void {
    $loc = new SourceLocation('');
    $site = static fn (array $fields): ReturnSite => new ReturnSite(new ArrayShapeT($fields), $loc);
    $engine = new StubTypeEngine(analyses: [
        ForwardedDataResource::class.'::toArray' => new ActionAnalysis(returns: array_map($site, $toArray)),
        ForwardedDataResource::class.'::with' => new ActionAnalysis(returns: [$site([new ArrayShapeField('data', new ArrayShapeT($with))])]),
    ]);

    expect((new SchemaConverter([new JsonResourceSchema, ...DefaultTypeMappers::all()], $engine, new ComponentRegistry))
        ->toSchema(new ClassT(ForwardedDataResource::class))->schema)->toBe($expected);
})->with([
    // Laravel leaves a body carrying `data` unwrapped, and merges with()'s `data` into that key.
    'its keys only join the carried object: the component stands' => [
        [[new ArrayShapeField('data', new ArrayShapeT([new ArrayShapeField('tag', ScalarT::string())]))]],
        [new ArrayShapeField('traced', ScalarT::bool())],
        ['$ref' => '#/components/schemas/ForwardedDataResource'],
    ],
    'a key both send becomes the list of both: restated inline' => [
        [[new ArrayShapeField('data', new ArrayShapeT([new ArrayShapeField('tag', ScalarT::string())]))]],
        [new ArrayShapeField('tag', ScalarT::string())],
        ['type' => 'object', 'properties' => ['data' => PaginationEnvelope::MERGED], 'required' => ['data']],
    ],
    // One branch returns [], so the component is its object or the empty list, and has no keys to restate.
    'carried on one branch of a body that may be empty: an object' => [
        [[new ArrayShapeField('data', new ArrayShapeT([new ArrayShapeField('tag', ScalarT::string())]))], []],
        [new ArrayShapeField('tag', ScalarT::string())],
        ['anyOf' => [
            ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/ForwardedDataResource']], 'required' => ['data']],
            ['type' => 'object'],
        ]],
    ],
]);

it('publishes a named collection whose collected resource cannot be named as a list of anything', function (): void {
    $components = new ComponentRegistry;
    ($this->convert)(new ClassT(StrayCollection::class), $components);

    expect($components->schemas()['StrayCollection'])->toBe(['type' => 'array', 'items' => []]);
});

it('reads with() unfiltered: a when() member is always sent, as its value or as {}', function (): void {
    expect(($this->convert)(new ClassT(ConditionalWithResource::class)))->toBe([
        'type' => 'object',
        'properties' => [
            'data' => ['$ref' => '#/components/schemas/ConditionalWithResource'],
            'debug' => ['anyOf' => [['type' => 'string'], ['type' => 'object', 'maxProperties' => 0]]],
        ],
        'required' => ['data', 'debug'],
    ]);
});

it('reads a with() holding a merge as unreadable, since Laravel sends the merge under a numeric key', function (): void {
    // Spliced as toArray's would be, `debug` would publish as a member no response carries.
    expect(($this->convert)(new ClassT(MergingWithResource::class)))->toBe([
        'type' => 'object',
        'properties' => ['data' => ['$ref' => '#/components/schemas/MergingWithResource']],
        'required' => ['data'],
    ]);
});

it('reads Laravel\'s own with() as unreadable once the $with property it returns is set', function (): void {
    $schema = ($this->convert)(new ClassT(WithPropertyResource::class), null, new RepresentationPolicy(resourceWrap: RepresentationPolicy::WRAP_DISABLED));

    expect($schema)->toBe(['anyOf' => [
        ['$ref' => '#/components/schemas/WithPropertyResource'],
        [
            'type' => 'object',
            'properties' => ['data' => ['$ref' => '#/components/schemas/WithPropertyResource']],
            'required' => ['data'],
        ],
    ]]);
});

it('publishes an unwrapped named collection bare or wrapped, since a paginator adds members', function (): void {
    $schema = ($this->convert)(new ClassT(ReleaseFeedCollection::class), null, new RepresentationPolicy(resourceWrap: RepresentationPolicy::WRAP_DISABLED));

    expect($schema)->toBe(['anyOf' => [
        ['$ref' => '#/components/schemas/ReleaseFeedCollection'],
        [
            'type' => 'object',
            'properties' => ['data' => ['$ref' => '#/components/schemas/ReleaseFeedCollection']],
            'required' => ['data'],
        ],
    ]]);
});
