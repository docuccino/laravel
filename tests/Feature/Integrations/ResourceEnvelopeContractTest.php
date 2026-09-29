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
use Docuccino\Laravel\Integrations\ApiResources\JsonApiResourceSchema;
use Docuccino\Laravel\Integrations\ApiResources\JsonResourceSchema;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ConditionalWithResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\DynamicMetaResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\EmptyBranchResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\LinkedReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\MergedDataResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\MergingWithResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\MeteredJsonApiResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\PartlyDynamicMetaResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseFeedCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\SparseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\TalliedReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\WithPropertyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Fluent;
use Opis\JsonSchema\Validator;

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
        MeteredJsonApiResource::class.'::toAttributes' => new ActionAnalysis(returns: [$site(new ArrayShapeT([new ArrayShapeField('title', ScalarT::string())]))]),
        MeteredJsonApiResource::class.'::toMeta' => new ActionAnalysis(returns: [$site(new ArrayShapeT([
            new ArrayShapeField('cached', UnionT::of([ScalarT::bool(), $missing])),
        ]))]),
    ]);

    // The published schema with every `$ref` inlined from the registry, as a validator reads it.
    $this->published = static function (string $fqcn, ?RepresentationPolicy $policy = null) use ($engine): object {
        $components = new ComponentRegistry;
        $schema = (new SchemaConverter([new JsonApiResourceSchema, new JsonResourceSchema, ...DefaultTypeMappers::all()], $engine, $components, $policy ?? new RepresentationPolicy))
            ->toSchema(new ClassT($fqcn))->schema;

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
