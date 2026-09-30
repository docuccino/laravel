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
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\ApiResources\CollectionKeys;
use Docuccino\Laravel\Integrations\ApiResources\JsonApiResourceSchema;
use Docuccino\Laravel\Integrations\ApiResources\JsonResourceSchema;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Integrations\Support\PaginationEnvelope;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldJsonApiResourceSchema;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\AppendedReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ChannelResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ChannelShelfCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ConditionalWithResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\DynamicMetaResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\EmptiedDataResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\EmptyBranchResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ForceWrappedResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ForwardedDataResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ForwardedReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\KeyedReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\KeyedReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\LinkedJsonApiResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\LinkedReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\MergedDataResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\MergingWithResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\MeteredJsonApiResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\PartlyDynamicMetaResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\PinnedKeysReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\PinnedKeysReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseFeedCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseResourceCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\RetaggedResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\SparseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\TalliedReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\VersionedJsonApiResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\WithPropertyResource;
use Docuccino\Laravel\Tests\Fixtures\TimacdonaldJsonApi\TimacdonaldArticleResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;
use Opis\JsonSchema\Validator;
use TiMacDonald\JsonApi\JsonApiResource as TimacdonaldJsonApiResource;
use TiMacDonald\JsonApi\JsonApiResourceCollection as TimacdonaldJsonApiResourceCollection;
use TiMacDonald\JsonApi\ServerImplementation;

/**
 * The root envelope checked against what Laravel's `ResourceResponse` actually sends: each response a
 * resource can produce is serialised by the framework and validated against the published schema. The
 * contract is Laravel's wrap-and-merge, so the framework — not this mapper — states the right answer.
 */
beforeEach(function (): void {
    $loc = new SourceLocation('');
    $site = static fn ($type): ReturnSite => new ReturnSite($type, $loc);
    $tag = new ArrayShapeT([new ArrayShapeField('tag', ScalarT::string())]);
    $missing = new ClassT(ResourceReflector::MISSING_VALUE);

    $engine = new StubTypeEngine(analyses: [
        ReleaseResource::class.'::toArray' => new ActionAnalysis(returns: [$site($tag)]),
        ReleaseResource::class.'::with' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('meta', new ClassT('stdClass')),
            new ArrayShapeField('version', ScalarT::string()),
        ]))]),
        DynamicMetaResource::class.'::toArray' => new ActionAnalysis(returns: [$site($tag)]),
        DynamicMetaResource::class.'::with' => new ActionAnalysis(returns: [$site(new MapT(ScalarT::string(), new UnknownT('mixed')))]),
        PartlyDynamicMetaResource::class.'::toArray' => new ActionAnalysis(returns: [
            $site(new MapT(ScalarT::string(), new UnknownT('mixed'))),
            $site($tag),
        ]),
        PartlyDynamicMetaResource::class.'::with' => new ActionAnalysis(returns: [
            $site(new ArrayShapeT([new ArrayShapeField('meta', new ArrayShapeT([new ArrayShapeField('query_count', ScalarT::int())]))])),
            $site(new MapT(ScalarT::string(), new UnknownT('mixed'))),
        ]),
        MergedDataResource::class.'::toArray' => new ActionAnalysis(returns: [$site($tag)]),
        MergedDataResource::class.'::with' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('data', new ArrayShapeT([new ArrayShapeField('traced', ScalarT::bool())])),
        ]))]),
        ReleaseCollection::class.'::with' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('meta', new ArrayShapeT([new ArrayShapeField('key', ScalarT::string())])),
        ]))]),
        TalliedReleaseCollection::class.'::with' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('meta', new ArrayShapeT([
                new ArrayShapeField('total', ScalarT::int()),
                new ArrayShapeField('key', ScalarT::string()),
            ])),
        ]))]),
        LinkedReleaseCollection::class.'::toArray' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('data', new ListT(new ClassT(ReleaseResource::class))),
            new ArrayShapeField('links', new ArrayShapeT([new ArrayShapeField('self', ScalarT::string())])),
        ]))]),
        EmptyBranchResource::class.'::toArray' => new ActionAnalysis(returns: [$site(new ArrayShapeT([])), $site($tag)]),
        SparseResource::class.'::toArray' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('tag', UnionT::of([ScalarT::string(), $missing])),
        ]))]),
        WithPropertyResource::class.'::toArray' => new ActionAnalysis(returns: [$site($tag)]),
        ConditionalWithResource::class.'::toArray' => new ActionAnalysis(returns: [$site($tag)]),
        ConditionalWithResource::class.'::with' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('debug', UnionT::of([ScalarT::string(), $missing])),
        ]))]),
        MergingWithResource::class.'::toArray' => new ActionAnalysis(returns: [$site($tag)]),
        MergingWithResource::class.'::with' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('version', ScalarT::string()),
            new ArrayShapeField(0, UnionT::of([
                new ClassT('Illuminate\\Http\\Resources\\MergeValue', [new ArrayShapeT([new ArrayShapeField('debug', ScalarT::string())])]),
                $missing,
            ])),
        ]))]),
        RetaggedResource::class.'::toArray' => new ActionAnalysis(returns: [$site($tag)]),
        RetaggedResource::class.'::with' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('data', new ArrayShapeT([
                new ArrayShapeField('tag', ScalarT::string()),
                new ArrayShapeField('traced', ScalarT::bool()),
            ])),
        ]))]),
        EmptiedDataResource::class.'::toArray' => new ActionAnalysis(returns: [$site(new ArrayShapeT([])), $site($tag)]),
        EmptiedDataResource::class.'::with' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('data', new ArrayShapeT([new ArrayShapeField('traced', ScalarT::bool())])),
        ]))]),
        ForwardedDataResource::class.'::toArray' => new ActionAnalysis(returns: [$site($tag)]),
        ForwardedDataResource::class.'::with' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('data', new MapT(ScalarT::string(), new UnknownT('mixed'))),
        ]))]),
        AppendedReleaseCollection::class.'::with' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('data', new ArrayShapeT([new ArrayShapeField('source', ScalarT::string())])),
        ]))]),
        ForwardedReleaseCollection::class.'::toArray' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('data', new ListT(new ClassT(ReleaseResource::class))),
        ]))]),
        ForwardedReleaseCollection::class.'::with' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('data', new ArrayShapeT([new ArrayShapeField('source', ScalarT::string())])),
        ]))]),
        ChannelResource::class.'::toArray' => new ActionAnalysis(returns: [$site(new ArrayShapeT([new ArrayShapeField('channel', ScalarT::string())]))]),
        KeyedReleaseResource::class.'::toArray' => new ActionAnalysis(returns: [$site($tag)]),
        PinnedKeysReleaseResource::class.'::toArray' => new ActionAnalysis(returns: [$site($tag)]),
        ForceWrappedResource::class.'::toArray' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('data', new ArrayShapeT([new ArrayShapeField('source', ScalarT::string())])),
            new ArrayShapeField('tag', ScalarT::string()),
        ]))]),
        MeteredJsonApiResource::class.'::toAttributes' => new ActionAnalysis(returns: [$site(new ArrayShapeT([new ArrayShapeField('title', ScalarT::string())]))]),
        MeteredJsonApiResource::class.'::toMeta' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('cached', UnionT::of([ScalarT::bool(), $missing])),
        ]))]),
        LinkedJsonApiResource::class.'::toAttributes' => new ActionAnalysis(returns: [$site(new ArrayShapeT([new ArrayShapeField('title', ScalarT::string())]))]),
        TimacdonaldArticleResource::class.'::toAttributes' => new ActionAnalysis(returns: [$site(new ArrayShapeT([new ArrayShapeField('title', ScalarT::string())]))]),
        LinkedJsonApiResource::class.'::toLinks' => new ActionAnalysis(returns: [$site(new ArrayShapeT([new ArrayShapeField('self', ScalarT::string())]))]),
    ]);

    // The published schema with every `$ref` inlined from the registry, as a validator reads it.
    $this->published = static function (string|ClassT $type, ?RepresentationPolicy $policy = null) use ($engine): object {
        $components = new ComponentRegistry;
        $schema = (new SchemaConverter([new JsonApiResourceSchema, new TimacdonaldJsonApiResourceSchema, new JsonResourceSchema, ...DefaultTypeMappers::all()], $engine, $components, $policy ?? new RepresentationPolicy))
            ->toSchema(is_string($type) ? new ClassT($type) : $type)->schema;

        $inline = static function (mixed $node, ?string $key = null) use (&$inline, $components): mixed {
            if (! is_array($node)) {
                return $node;
            }
            if (isset($node['$ref']) && is_string($node['$ref'])) {
                return $inline($components->schemas()[substr($node['$ref'], strlen('#/components/schemas/'))]);
            }
            if ($node === [] && ! in_array($key, ['required', 'enum'], true)) {
                return new stdClass;
            }
            $out = [];
            foreach ($node as $k => $v) {
                $out[$k] = $inline($v, is_string($k) ? $k : $key);
            }

            return array_is_list($out) && in_array($key, ['required', 'enum', 'type', 'examples', 'anyOf', 'allOf', 'oneOf'], true) ? $out : (object) $out;
        };

        $resolved = $inline($schema);
        assert($resolved instanceof stdClass);

        return $resolved;
    };

    $this->sent = static fn (JsonResource $resource, array $query = []): mixed => json_decode(
        (string) $resource->toResponse(Request::create('/', 'GET', $query))->getContent(),
    );

    $this->accepts = static fn (object $schema, mixed $body): bool => (new Validator)->validate($body, $schema)->isValid();
});

it('accepts every body an unwrapped resource sends when its with() cannot be read', function (): void {
    JsonResource::withoutWrapping();

    try {
        $schema = ($this->published)(DynamicMetaResource::class, new RepresentationPolicy(resourceWrap: RepresentationPolicy::WRAP_DISABLED));
        $bare = ($this->sent)(new DynamicMetaResource((object) ['tag' => 'a']));
        $wrapped = ($this->sent)(new DynamicMetaResource((object) ['tag' => 'a']), ['trace' => '1']);
    } finally {
        JsonResource::wrap('data');
    }

    // Laravel wraps the unwrapped resource under `data` the moment with() returns anything.
    expect($wrapped)->toEqual((object) ['data' => (object) ['tag' => 'a'], 'trace' => '1'])
        ->and(($this->accepts)($schema, $bare))->toBeTrue()
        ->and(($this->accepts)($schema, $wrapped))->toBeTrue();
});

it('accepts a with() branch that could not be read omitting the members another branch adds', function (): void {
    $schema = ($this->published)(PartlyDynamicMetaResource::class);

    $debug = ($this->sent)(new PartlyDynamicMetaResource(new Fluent(['tag' => 'a'])), ['debug' => '1']);
    $plain = ($this->sent)(new PartlyDynamicMetaResource(new Fluent(['tag' => 'a'])));
    // toArray's unreadable branch returns the model's own attributes, which need not include `tag`.
    $raw = ($this->sent)(new PartlyDynamicMetaResource(new Fluent(['name' => 'n'])), ['raw' => '1']);

    expect(($this->accepts)($schema, $debug))->toBeTrue()
        ->and(($this->accepts)($schema, $plain))->toBeTrue()
        ->and(($this->accepts)($schema, $raw))->toBeTrue();
});

it('wraps an unwrapped resource whose with() returns only the data key, merged into the data', function (): void {
    $sent = ($this->sent)(new MergedDataResource((object) ['tag' => 'a']));

    expect($sent)->toEqual((object) ['data' => (object) ['tag' => 'a', 'traced' => true]])
        ->and(($this->accepts)(($this->published)(MergedDataResource::class), $sent))->toBeTrue();
});

it('accepts the data a with() data key is merged into, which is not the list or the object toArray builds', function (): void {
    $items = [(object) ['tag' => 'a'], (object) ['tag' => 'b']];
    $collection = ($this->published)(AppendedReleaseCollection::class);
    $plain = ($this->sent)(new AppendedReleaseCollection(collect($items)));
    $paged = ($this->sent)(new AppendedReleaseCollection(new LengthAwarePaginator($items, 2, 15)));
    $forwarded = ($this->sent)(new ForwardedReleaseCollection(collect($items)));
    $retagged = ($this->sent)(new RetaggedResource((object) ['tag' => 'a']));

    // array_merge_recursive: the object joins the list's positions, and a key both send becomes the list of both.
    expect($plain->data)->toEqual((object) ['0' => (object) ['tag' => 'a'], '1' => (object) ['tag' => 'b'], 'source' => 'ledger'])
        ->and($paged->data)->toEqual($plain->data)
        ->and($forwarded->data)->toEqual($plain->data)
        ->and($retagged->data)->toEqual((object) ['tag' => ['a', 'pinned'], 'traced' => true])
        ->and(($this->accepts)($collection, $plain))->toBeTrue()
        ->and(($this->accepts)($collection, $paged))->toBeTrue()
        ->and(($this->accepts)(($this->published)(ForwardedReleaseCollection::class), $forwarded))->toBeTrue()
        ->and(($this->accepts)(($this->published)(RetaggedResource::class), $retagged))->toBeTrue()
        // Neither the list's items nor the resource's string is what is sent: the data is widened to it.
        ->and($collection->properties->data)->toEqual((object) PaginationEnvelope::MERGED)
        ->and(($this->published)(RetaggedResource::class)->properties->data)->toEqual((object) PaginationEnvelope::MERGED)
        ->and(($this->published)(ForwardedReleaseCollection::class)->properties->data)->toEqual((object) PaginationEnvelope::MERGED)
        // Still an array or an object, which is all a response can send there.
        ->and(($this->accepts)($collection, (object) ['data' => 'x']))->toBeFalse();
});

it('accepts a with() data key whose keys may be the resource\'s own, widened, and keeps a body its keys only join', function (): void {
    $forwarded = ($this->sent)(new ForwardedDataResource((object) ['tag' => 'a']), ['extra' => ['tag' => 'x']]);
    $emptied = ($this->sent)(new EmptiedDataResource((object) ['tag' => 'a']), ['empty' => '1']);
    $keyed = ($this->sent)(new EmptiedDataResource((object) ['tag' => 'a']));

    // The empty body's [] takes the member's keys and is sent as an object — one the open body requires nothing of.
    expect($forwarded->data)->toEqual((object) ['tag' => ['a', 'x']])
        ->and($emptied->data)->toEqual((object) ['traced' => true])
        ->and($keyed->data)->toEqual((object) ['tag' => 'a', 'traced' => true])
        ->and(($this->published)(ForwardedDataResource::class)->properties->data)->toEqual((object) PaginationEnvelope::MERGED)
        // The body the member only joins stands, as the same toArray with no with() publishes it.
        ->and(($this->published)(EmptiedDataResource::class)->properties->data)->toEqual(($this->published)(EmptyBranchResource::class)->properties->data)
        ->and(($this->accepts)(($this->published)(ForwardedDataResource::class), $forwarded))->toBeTrue()
        ->and(($this->accepts)(($this->published)(EmptiedDataResource::class), $emptied))->toBeTrue()
        ->and(($this->accepts)(($this->published)(EmptiedDataResource::class), $keyed))->toBeTrue();
});

it('accepts the data a with() data key is merged into under withoutWrapping', function (): void {
    JsonResource::withoutWrapping();

    try {
        $policy = new RepresentationPolicy(resourceWrap: RepresentationPolicy::WRAP_DISABLED);
        $collection = ($this->published)(AppendedReleaseCollection::class, $policy);
        $resource = ($this->published)(RetaggedResource::class, $policy);
        $plain = ($this->sent)(new AppendedReleaseCollection(collect([(object) ['tag' => 'a']])));
        $retagged = ($this->sent)(new RetaggedResource((object) ['tag' => 'a']));
    } finally {
        JsonResource::wrap('data');
    }

    // with() returning anything wraps the unwrapped body under data, and the merge follows.
    expect($plain)->toEqual((object) ['data' => (object) ['0' => (object) ['tag' => 'a'], 'source' => 'ledger']])
        ->and($retagged->data->tag)->toBe(['a', 'pinned'])
        ->and(($this->accepts)($collection, $plain))->toBeTrue()
        ->and(($this->accepts)($resource, $retagged))->toBeTrue();
});

it('accepts a named collection with Laravel\'s own toArray, paginated or not, with() meta merged in', function (): void {
    $schema = ($this->published)(ReleaseCollection::class);
    $items = [(object) ['tag' => 'a'], (object) ['tag' => 'b']];

    $plain = ($this->sent)(new ReleaseCollection(collect($items)));
    $paged = ($this->sent)(new ReleaseCollection(new LengthAwarePaginator($items, 2, 15)));

    // Pagination merges its own meta into with()'s recursively: `key` survives beside the counters.
    expect($plain->data)->toEqual([(object) ['tag' => 'a'], (object) ['tag' => 'b']])
        ->and($paged->meta->key)->toBe('value')
        ->and($paged->meta->total)->toBe(2)
        ->and(($this->accepts)($schema, $plain))->toBeTrue()
        ->and(($this->accepts)($schema, $paged))->toBeTrue();
});

it('accepts a with() meta key a paginated named collection also sends, as the array Laravel merges them into', function (): void {
    $schema = ($this->published)(TalliedReleaseCollection::class);
    $items = [(object) ['tag' => 'a'], (object) ['tag' => 'b']];

    $plain = ($this->sent)(new TalliedReleaseCollection(collect($items)));
    $paged = ($this->sent)(new TalliedReleaseCollection(new LengthAwarePaginator($items, 2, 15)));

    // array_merge_recursive keeps both values of a key both sides send, as a list.
    expect($plain->meta->total)->toBe(5)
        ->and($paged->meta->total)->toBe([2, 5])
        ->and($paged->meta->key)->toBe('value')
        ->and(($this->accepts)($schema, $plain))->toBeTrue()
        ->and(($this->accepts)($schema, $paged))->toBeTrue()
        // The key the page never sends keeps its type.
        ->and(($this->accepts)($schema, (object) [...(array) $plain, 'meta' => (object) ['total' => 5, 'key' => [1]]]))->toBeFalse();
});

it('does not wrap a collection whose toArray already returns its data key', function (): void {
    $sent = ($this->sent)(new LinkedReleaseCollection(collect([(object) ['tag' => 'a']])));

    expect(array_keys((array) $sent))->toBe(['data', 'links'])
        ->and(($this->accepts)(($this->published)(LinkedReleaseCollection::class), $sent))->toBeTrue();
});

it('accepts the empty JSON array a toArray sends when it returns no key at all', function (string $fqcn, array $query): void {
    $schema = ($this->published)($fqcn);
    $keyed = ($this->sent)(new $fqcn((object) ['tag' => 'a']), ['tag' => '1']);
    $empty = ($this->sent)(new $fqcn((object) ['tag' => 'a']), $query);

    // resolve() hands json_encode a PHP array, and an empty one encodes as `[]`, never `{}`.
    expect($empty)->toEqual((object) ['data' => []])
        ->and(($this->accepts)($schema, $keyed))->toBeTrue()
        ->and(($this->accepts)($schema, $empty))->toBeTrue()
        ->and(($this->accepts)($schema, (object) ['data' => [1]]))->toBeFalse();
})->with([
    'a branch returning []' => [EmptyBranchResource::class, ['empty' => '1']],
    'every key conditional and filtered out' => [SparseResource::class, []],
]);

it('accepts the members the $with property adds to an unwrapped resource', function (): void {
    JsonResource::withoutWrapping();

    try {
        $schema = ($this->published)(WithPropertyResource::class, new RepresentationPolicy(resourceWrap: RepresentationPolicy::WRAP_DISABLED));
        $sent = ($this->sent)(new WithPropertyResource((object) ['tag' => 'a']));
    } finally {
        JsonResource::wrap('data');
    }

    expect($sent)->toEqual((object) ['data' => (object) ['tag' => 'a'], 'meta' => (object) ['version' => 1]])
        ->and(($this->accepts)($schema, $sent))->toBeTrue();
});

it('accepts a with() member Laravel sends unfiltered, whatever its condition', function (string $fqcn, string $member): void {
    $schema = ($this->published)($fqcn);
    $off = ($this->sent)(new $fqcn((object) ['tag' => 'a']));
    $on = ($this->sent)(new $fqcn((object) ['tag' => 'a']), ['debug' => '1']);

    // with() is merged as returned: a false condition's MissingValue still sends its key, as `{}`.
    expect(property_exists($off, $member))->toBeTrue()
        ->and(($this->accepts)($schema, $off))->toBeTrue()
        ->and(($this->accepts)($schema, $on))->toBeTrue();
})->with([
    'when()' => [ConditionalWithResource::class, 'debug'],
    'mergeWhen()' => [MergingWithResource::class, '0'],
]);

it('accepts a JSON:API meta member Laravel sends unfiltered, whatever its condition', function (): void {
    $schema = ($this->published)(MeteredJsonApiResource::class);
    $model = new Fluent(['id' => 1, 'title' => 't']);
    $off = ($this->sent)(new MeteredJsonApiResource($model));
    $on = ($this->sent)(new MeteredJsonApiResource($model), ['cached' => '1']);

    // toMeta is merged as returned, unlike toAttributes: a false when() sends its key as `{}`.
    expect($off->data->meta)->toEqual((object) ['cached' => new stdClass])
        ->and(($this->accepts)($schema, $off))->toBeTrue()
        ->and(($this->accepts)($schema, $on))->toBeTrue();
});

it('accepts a paginated named collection that has no with() of its own sent unwrapped', function (): void {
    JsonResource::withoutWrapping();
    $items = [(object) ['tag' => 'a']];

    // Whether the collection holds a paginator is the caller's choice, not the class's, so both are sent.
    try {
        $schema = ($this->published)(ReleaseFeedCollection::class, new RepresentationPolicy(resourceWrap: RepresentationPolicy::WRAP_DISABLED));
        $plain = ($this->sent)(new ReleaseFeedCollection(collect($items)));
        $paged = ($this->sent)(new ReleaseFeedCollection(new LengthAwarePaginator($items, 1, 15)));
    } finally {
        JsonResource::wrap('data');
    }

    // Pagination passes its links and meta as with(), so the paginated list is wrapped under data.
    expect($plain)->toEqual([(object) ['tag' => 'a']])
        ->and(array_keys((array) $paged))->toBe(['data', 'links', 'meta'])
        ->and(($this->accepts)($schema, $plain))->toBeTrue()
        ->and(($this->accepts)($schema, $paged))->toBeTrue();
});

it('rejects a body the published envelope does not allow, so an accepting run proves something', function (): void {
    $schema = ($this->published)(ReleaseResource::class);

    // A ReleaseResource response always carries meta and version; one without them is not what is sent.
    expect(($this->accepts)($schema, ($this->sent)(new ReleaseResource((object) ['tag' => 'a']))))->toBeTrue()
        ->and(($this->accepts)($schema, (object) ['data' => (object) ['tag' => 'a']]))->toBeFalse();
});

it('publishes the resource a named collection collects as the installed framework resolves it', function (): void {
    $schema = ($this->published)(ReleaseResourceCollection::class);
    $sent = ($this->sent)(new ReleaseResourceCollection(collect([new Fluent(['tag' => 'a', 'channel' => 'c'])])));
    $release = (object) ['tag' => 'a'];
    $channel = (object) ['channel' => 'c'];

    // #[Collects] names what a framework shipping it maps each item into, ahead of the class's name;
    // one without the attribute finds ReleaseResource by that name.
    $reads = class_exists(ResourceReflector::COLLECTS_ATTRIBUTE);
    expect($sent->data)->toEqual([$reads ? $channel : $release])
        ->and(($this->accepts)($schema, $sent))->toBeTrue()
        ->and(($this->accepts)($schema, (object) ['data' => [$reads ? $release : $channel]]))->toBeFalse();

    // A parent's #[Collects] is not read, so a collection whose name finds nothing sends each item as it is.
    $shelf = ($this->sent)(new ChannelShelfCollection(collect([new Fluent(['tag' => 'a', 'channel' => 'c'])])));
    expect($shelf->data)->toEqual([(object) ['tag' => 'a', 'channel' => 'c']])
        ->and(($this->published)(ChannelShelfCollection::class)->properties->data->items)->toEqual(new stdClass);
});

it('publishes a collection that keeps its keys as the array or object its keys make it', function (callable $make, bool $preserves): void {
    $keyed = collect([3 => new Fluent(['tag' => 'a']), 7 => new Fluent(['tag' => 'b'])]);
    $collection = $make($keyed);
    $schema = ($this->published)(new ClassT($collection::class, $collection instanceof AnonymousResourceCollection ? [new ClassT($collection->collects)] : []));

    $sentKeyed = ($this->sent)($collection);
    $sentListed = ($this->sent)($make(collect([new Fluent(['tag' => 'a'])])));

    // Kept integer keys that are not 0…n-1 encode as an object; renumbered ones, and kept ones that run
    // from 0, as a list.
    expect(is_object($sentKeyed->data))->toBe($preserves)
        ->and($sentListed->data)->toEqual([(object) ['tag' => 'a']])
        ->and(($this->accepts)($schema, $sentKeyed))->toBeTrue()
        ->and(($this->accepts)($schema, $sentListed))->toBeTrue()
        // A collection Laravel renumbers stays the list it is always sent as.
        ->and($schema->properties->data->type)->toBe($preserves ? ['array', 'object'] : 'array')
        // Either way each item is still the resource.
        ->and(($this->accepts)($schema, (object) ['data' => (object) ['3' => (object) ['name' => 'n']]]))->toBeFalse();
})->with([
    '$preserveKeys on the collected resource' => [fn (Collection $items): AnonymousResourceCollection => KeyedReleaseResource::collection($items), true],
    '#[PreserveKeys] on the collected resource' => [fn (Collection $items): AnonymousResourceCollection => PinnedKeysReleaseResource::collection($items), class_exists(CollectionKeys::PRESERVE_KEYS_ATTRIBUTE)],
    '$preserveKeys on a named collection' => [fn (Collection $items): KeyedReleaseCollection => new KeyedReleaseCollection($items), true],
    '#[PreserveKeys] on a named collection' => [fn (Collection $items): PinnedKeysReleaseCollection => new PinnedKeysReleaseCollection($items), class_exists(CollectionKeys::PRESERVE_KEYS_ATTRIBUTE)],
    'neither' => [fn (Collection $items): AnonymousResourceCollection => ReleaseResource::collection($items), false],
]);

it('wraps a body that carries its own wrap key where the resource forces its wrap', function (): void {
    $schema = ($this->published)(ForceWrappedResource::class);
    $sent = ($this->sent)(new ForceWrappedResource((object) ['tag' => 'a']));
    $body = (object) ['data' => (object) ['source' => 'ledger'], 'tag' => 'a'];

    // $forceWrapping wraps whatever the body carries; a framework without the property leaves a body
    // already carrying its key as it is.
    $forces = property_exists(JsonResource::class, 'forceWrapping');
    expect($sent)->toEqual($forces ? (object) ['data' => $body] : $body)
        ->and(($this->accepts)($schema, $sent))->toBeTrue()
        ->and(($this->accepts)($schema, $forces ? $body : (object) ['data' => $body]))->toBeFalse();
});

it('wraps a body carrying its own wrap key, and a JSON:API document, where every resource forces its wrap', function (): void {
    if (! property_exists(JsonResource::class, 'forceWrapping')) {
        $this->markTestSkipped('The installed framework has no $forceWrapping.');
    }

    JsonResource::$forceWrapping = true;

    try {
        $collection = ($this->published)(LinkedReleaseCollection::class);
        $document = ($this->published)(MeteredJsonApiResource::class);
        $linked = ($this->sent)(new LinkedReleaseCollection(collect([(object) ['tag' => 'a']])));
        $jsonApi = ($this->sent)(new MeteredJsonApiResource(new Fluent(['id' => 1, 'title' => 't'])));
    } finally {
        JsonResource::$forceWrapping = false;
    }

    // The static is shared down the hierarchy, and a JSON:API resource's resolve() returns its own `data`.
    expect(array_keys((array) $linked))->toBe(['data'])
        ->and(array_keys((array) $linked->data))->toBe(['data', 'links'])
        ->and($jsonApi->data->data->type)->toBe('metered')
        ->and(($this->accepts)($collection, $linked))->toBeTrue()
        ->and(($this->accepts)($document, $jsonApi))->toBeTrue()
        ->and(($this->accepts)($collection, $linked->data))->toBeFalse()
        ->and(($this->accepts)($document, $jsonApi->data))->toBeFalse();
});

/*
 * A JSON:API document's top-level members, and the links of its resource object, as Laravel's own
 * JsonApiResource sends them. `configure()` is boot state, so each test puts the static back.
 */

it('requires the jsonapi object configure() adds to every JSON:API document, typed as it is sent', function (Closure $send, ClassT $type): void {
    JsonApiResource::configure(version: '1.1', ext: ['https://jsonapi.org/ext/atomic'], profile: ['https://example.com/profiles/flexible'], meta: ['copyright' => 'Example']);

    try {
        $schema = ($this->published)($type);
        $sent = ($this->sent)($send(new Fluent(['id' => 1, 'title' => 't'])));
    } finally {
        JsonApiResource::$jsonApiInformation = [];
    }

    // Laravel adds it from with() on every document while the static is set, so a body without it,
    // or with a member of another JSON type than the one configured, is not one it sends.
    $without = clone $sent;
    unset($without->jsonapi);
    $numbered = clone $sent;
    $numbered->jsonapi = (object) [...(array) $sent->jsonapi, 'version' => 1.1];

    expect($sent->jsonapi)->toEqual((object) [
        'version' => '1.1',
        'ext' => ['https://jsonapi.org/ext/atomic'],
        'profile' => ['https://example.com/profiles/flexible'],
        'meta' => (object) ['copyright' => 'Example'],
    ])
        ->and(($this->accepts)($schema, $sent))->toBeTrue()
        ->and(($this->accepts)($schema, $without))->toBeFalse()
        ->and(($this->accepts)($schema, $numbered))->toBeFalse();
})->with([
    'a resource' => [
        static fn (Fluent $model): JsonResource => new MeteredJsonApiResource($model),
        new ClassT(MeteredJsonApiResource::class),
    ],
    'a collection' => [
        static fn (Fluent $model): JsonResource => MeteredJsonApiResource::collection(collect([$model])),
        new ClassT(ResourceReflector::JSON_API_COLLECTION, [new ClassT(MeteredJsonApiResource::class)]),
    ],
    'a length-aware page' => [
        static fn (Fluent $model): JsonResource => MeteredJsonApiResource::collection(new LengthAwarePaginator([$model], 1, 15)),
        new ClassT(ResourceReflector::JSON_API_COLLECTION, [new ClassT(MeteredJsonApiResource::class)]),
    ],
    'a cursor page' => [
        static fn (Fluent $model): JsonResource => MeteredJsonApiResource::collection(new CursorPaginator([$model], 15)),
        new ClassT(ResourceReflector::JSON_API_COLLECTION, [new ClassT(MeteredJsonApiResource::class)]),
    ],
]);

it('publishes no jsonapi object while nothing configured one', function (): void {
    $single = ($this->published)(MeteredJsonApiResource::class);
    $collection = ($this->published)(new ClassT(ResourceReflector::JSON_API_COLLECTION, [new ClassT(MeteredJsonApiResource::class)]));
    $model = new Fluent(['id' => 1, 'title' => 't']);

    expect(($this->sent)(new MeteredJsonApiResource($model)))->not->toHaveProperty('jsonapi')
        ->and(($this->sent)(MeteredJsonApiResource::collection(collect([$model]))))->not->toHaveProperty('jsonapi')
        ->and($single->properties)->not->toHaveProperty('jsonapi')
        ->and($collection->properties)->not->toHaveProperty('jsonapi');
});

it('reads a jsonapi object a resource declares for the resource, and not for its collection', function (): void {
    // A single resource reads `static::$jsonApiInformation`; its collection reads the base class's.
    $model = new Fluent(['id' => 1]);
    $single = ($this->sent)(new VersionedJsonApiResource($model));
    $collection = ($this->sent)(VersionedJsonApiResource::collection(collect([$model])));
    $withoutIt = clone $single;
    unset($withoutIt->jsonapi);

    expect($single->jsonapi)->toEqual((object) ['version' => '1.0'])
        ->and($collection)->not->toHaveProperty('jsonapi')
        ->and(($this->accepts)(($this->published)(VersionedJsonApiResource::class), $single))->toBeTrue()
        ->and(($this->accepts)(($this->published)(VersionedJsonApiResource::class), $withoutIt))->toBeFalse()
        ->and(($this->published)(new ClassT(ResourceReflector::JSON_API_COLLECTION, [new ClassT(VersionedJsonApiResource::class)]))->properties)
        ->not->toHaveProperty('jsonapi');
});

it('keeps the jsonapi object beside the outer data where every resource forces its wrap', function (): void {
    if (! property_exists(JsonResource::class, 'forceWrapping')) {
        $this->markTestSkipped('The installed framework has no $forceWrapping.');
    }

    JsonResource::$forceWrapping = true;
    JsonApiResource::configure(version: '1.1');

    try {
        $schema = ($this->published)(MeteredJsonApiResource::class);
        $sent = ($this->sent)(new MeteredJsonApiResource(new Fluent(['id' => 1, 'title' => 't'])));
    } finally {
        JsonResource::$forceWrapping = false;
        JsonApiResource::$jsonApiInformation = [];
    }

    // with() merges beside the wrap key, not into the document resolve() wrapped a second time.
    $nested = (object) ['data' => (object) ['data' => $sent->data->data, 'jsonapi' => $sent->jsonapi]];

    expect(array_keys((array) $sent))->toBe(['data', 'jsonapi'])
        ->and(($this->accepts)($schema, $sent))->toBeTrue()
        ->and(($this->accepts)($schema, $nested))->toBeFalse();
});

it('accepts the links Laravel sends as toLinks returns them, and the resources a request includes', function (): void {
    $schema = ($this->published)(LinkedJsonApiResource::class);
    $author = new class extends Model
    {
        protected $table = 'authors';
    };
    $author->forceFill(['id' => 7, 'name' => 'n']);
    $article = new class extends Model {};
    $article->forceFill(['id' => 1, 'title' => 't']);
    $article->setRelation('author', $author);

    $plain = ($this->sent)(new LinkedJsonApiResource($article));
    $included = ($this->sent)(new LinkedJsonApiResource($article), ['include' => 'author']);

    // Laravel's toLinks is sent as returned — a URL string here, never a `{href}` link object — and an
    // included entry is a resource object, whose identity every one carries.
    $asObject = clone $plain;
    $asObject->data = (object) [...(array) $plain->data, 'links' => (object) ['self' => (object) ['href' => '/linked/1']]];
    $anonymous = clone $included;
    $anonymous->included = [(object) ['attributes' => $included->included[0]->attributes]];

    expect($plain->data->links)->toEqual((object) ['self' => '/linked/1'])
        ->and($plain)->not->toHaveProperty('included')
        ->and($included->included[0]->id)->toBe('7')
        ->and(($this->accepts)($schema, $plain))->toBeTrue()
        ->and(($this->accepts)($schema, $included))->toBeTrue()
        ->and(($this->accepts)($schema, $asObject))->toBeFalse()
        ->and(($this->accepts)($schema, $anonymous))->toBeFalse();
});

it('accepts the jsonapi object a timacdonald server implementation adds, which only the request decides', function (): void {
    $single = new ClassT(TimacdonaldArticleResource::class);
    $collection = new ClassT(TimacdonaldJsonApiResourceCollection::class, [$single]);
    $article = new class extends Model {};
    $article->forceFill(['id' => 1, 'title' => 't']);
    $unbound = [($this->published)($single), ($this->published)($collection)];

    $implementation = null;
    TimacdonaldJsonApiResource::resolveServerImplementationUsing(static function () use (&$implementation): ?ServerImplementation {
        return $implementation;
    });

    try {
        $bound = [($this->published)($single), ($this->published)($collection)];
        $none = [($this->sent)(new TimacdonaldArticleResource($article)), ($this->sent)(TimacdonaldArticleResource::collection(collect([$article])))];
        $implementation = new ServerImplementation('1.0', ['a' => 1]);
        $some = [($this->sent)(new TimacdonaldArticleResource($article)), ($this->sent)(TimacdonaldArticleResource::collection(collect([$article])))];
    } finally {
        app()->offsetUnset(TimacdonaldJsonApiResource::class.':$serverImplementationResolver');
    }

    // The callback may return nothing per request, so the member is optional — and where it is sent it
    // is the implementation's version and meta, which a versionless object is not.
    expect($unbound[0]->properties)->not->toHaveProperty('jsonapi')
        ->and($unbound[1]->properties)->not->toHaveProperty('jsonapi')
        ->and($some[0]->jsonapi)->toEqual((object) ['version' => '1.0', 'meta' => (object) ['a' => 1]])
        ->and($none[0])->not->toHaveProperty('jsonapi');
    foreach ([0, 1] as $i) {
        $versionless = clone $some[$i];
        $versionless->jsonapi = (object) ['meta' => (object) ['a' => 1]];

        expect(($this->accepts)($bound[$i], $some[$i]))->toBeTrue()
            ->and(($this->accepts)($bound[$i], $none[$i]))->toBeTrue()
            ->and(($this->accepts)($bound[$i], $versionless))->toBeFalse();
    }
});

it('accepts a timacdonald resource as the installed release sends it, and refuses its attributes alone wherever it sends a resource object', function (): void {
    $article = new class extends Model {};
    $article->forceFill(['id' => 1, 'title' => 't']);
    $schemas = [($this->published)(TimacdonaldArticleResource::class), ($this->published)(new ClassT(TimacdonaldJsonApiResourceCollection::class, [new ClassT(TimacdonaldArticleResource::class)]))];
    $sent = [($this->sent)(new TimacdonaldArticleResource($article)), ($this->sent)(TimacdonaldArticleResource::collection(collect([$article])))];
    $attributesOnly = [(object) ['data' => (object) ['title' => 't']], (object) ['data' => [(object) ['title' => 't']]]];

    // Laravel 12.45 and later send what resolveResourceData() returns, which the package declares to send
    // its resource object only from v1.0.0-beta.10: before that the body carries no identity at all.
    $sends = timacdonaldSendsResourceObjects();

    expect(property_exists($sent[0]->data, 'id'))->toBe($sends)
        ->and(property_exists($sent[1]->data[0], 'id'))->toBe($sends);
    foreach ([0, 1] as $i) {
        expect(($this->accepts)($schemas[$i], $sent[$i]))->toBeTrue()
            ->and(($this->accepts)($schemas[$i], $attributesOnly[$i]))->toBe(! $sends);
    }
});
