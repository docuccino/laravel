<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Extensions\EnumerableTypeToSchema;
use Docuccino\Laravel\Support\FrameworkClasses;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;
use Opis\JsonSchema\Validator;

/**
 * A Laravel collection returned from an action — bare, or through `response()->json()` — checked against
 * what the framework sends for it: `Router::toResponse()` hands it to a `JsonResponse`, which sends
 * `json_encode` of the array the collection holds, keys included. So the keys decide list or object, and
 * the framework, not the mapper, states the right answer. Each row's type is the one the real engine
 * recovers for that call (`KeyedCollectionTest` in the fixture group): the collection, with its key type.
 */
beforeEach(function (): void {
    $this->item = new ArrayShapeT([new ArrayShapeField('name', ScalarT::string())]);

    $this->published = function (DType $type): array {
        $converter = new SchemaConverter([new EnumerableTypeToSchema, ...DefaultTypeMappers::all()], new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy);

        return $converter->toSchema($type)->schema;
    };

    // An empty PHP array in a schema position is `{}`, as the document emits it.
    $this->validatable = static function (array $schema): object {
        $walk = static function (mixed $node, ?string $key = null) use (&$walk): mixed {
            if (! is_array($node)) {
                return $node;
            }
            $listed = in_array($key, ['type', 'required', 'enum'], true);
            if ($node === [] && ! $listed) {
                return new stdClass;
            }
            $out = [];
            foreach ($node as $k => $v) {
                $out[$k] = $walk($v, is_string($k) ? $k : $key);
            }

            return array_is_list($out) && $listed ? $out : (object) $out;
        };

        $object = $walk($schema);
        assert($object instanceof stdClass);

        return $object;
    };

    $this->sent = static fn (Enumerable $collection): mixed => json_decode(
        (string) Router::toResponse(Request::create('/'), $collection)->getContent(),
    );

    $this->accepts = fn (array $schema, mixed $body): bool => (new Validator)->validate($body, ($this->validatable)($schema))->isValid();
});

it('publishes a collection as the list or object Laravel sends it as, whatever its key type', function (string $as, callable $make, bool $object): void {
    $rows = collect([['name' => 'bo', 'id' => 7], ['name' => 'ada', 'id' => 3], ['name' => 'cy', 'id' => 5], ['name' => 'bo', 'id' => 9]]);
    $int = new ClassT(Collection::class, [ScalarT::int(), $this->item]);
    $type = match ($as) {
        'int' => $int,
        'string' => new ClassT(Collection::class, [ScalarT::string(), $this->item]),
        'array-key' => new ClassT(Collection::class, [UnionT::of([ScalarT::int(), ScalarT::string()]), $this->item]),
    };
    $schema = ($this->published)($type);
    $sent = ($this->sent)($make($rows)->map(static fn (array $row): array => ['name' => $row['name']]));

    // The body the framework sends, list or object, is valid against what is published.
    expect(is_object($sent))->toBe($object)
        ->and(($this->accepts)($schema, $sent))->toBeTrue();
})->with([
    // A query's rows, and the calls that renumber or keep a list: sent as a list.
    'a query\'s rows, as fetched' => ['int', static fn (Collection $rows): Collection => $rows, false],
    'filter()->values()' => ['int', static fn (Collection $rows): Collection => $rows->filter(static fn (array $row): bool => $row['id'] !== 7)->values(), false],
    'sortBy()->values()' => ['int', static fn (Collection $rows): Collection => $rows->sortBy('name')->values(), false],
    'map() over a list' => ['int', static fn (Collection $rows): Collection => $rows->map(static fn (array $row): array => $row), false],
    'take() a count of a list' => ['int', static fn (Collection $rows): Collection => $rows->take(2), false],
    'pluck() without a key' => ['int', static fn (Collection $rows): Collection => $rows->pluck('name')->map(static fn (string $name): array => ['name' => $name]), false],
    // Typed exactly as the calls above, the calls that drop or move items keep their keys, and a gapped
    // or reordered list is sent as an object.
    'filter()' => ['int', static fn (Collection $rows): Collection => $rows->filter(static fn (array $row): bool => $row['id'] !== 7), true],
    'reject()' => ['int', static fn (Collection $rows): Collection => $rows->reject(static fn (array $row): bool => $row['id'] === 3), true],
    'where()' => ['int', static fn (Collection $rows): Collection => $rows->where('name', 'ada'), true],
    'unique()' => ['int', static fn (Collection $rows): Collection => $rows->unique(static fn (array $row): string => $row['name'] === 'ada' ? 'bo' : $row['name']), true],
    'sortBy() on a list' => ['int', static fn (Collection $rows): Collection => $rows->sortBy('name'), true],
    'sortBy() then map()' => ['int', static fn (Collection $rows): Collection => $rows->sortBy('name')->map(static fn (array $row): array => $row), true],
    'slice() past the start' => ['int', static fn (Collection $rows): Collection => $rows->slice(1), true],
    'take() from the end' => ['int', static fn (Collection $rows): Collection => $rows->take(-2), true],
    // A string key is no proof of an object: none at all is sent as `[]`, and PHP turns a numeric string
    // back into an int, so keys that read 0…n-1 are sent as a list.
    'keyBy() with a string closure' => ['string', static fn (Collection $rows): Collection => $rows->keyBy(static fn (array $row): string => $row['name'].$row['id']), true],
    'mapWithKeys() to a string key' => ['string', static fn (Collection $rows): Collection => $rows->mapWithKeys(static fn (array $row): array => [$row['name'].$row['id'] => $row]), true],
    'keyBy() a string closure over no rows' => ['string', static fn (Collection $rows): Collection => $rows->take(0)->keyBy(static fn (array $row): string => $row['name']), false],
    'keyBy() a numeric string' => ['string', static fn (Collection $rows): Collection => $rows->values()->keyBy(static fn (array $row, int $index): string => (string) $index), false],
    // `keyBy('attribute')` is typed `array-key`: the attribute's values decide.
    'keyBy() a string attribute' => ['array-key', static fn (Collection $rows): Collection => $rows->keyBy('name'), true],
    'keyBy() an int attribute' => ['array-key', static fn (Collection $rows): Collection => $rows->keyBy('id'), true],
]);

it('refuses the body its type rules out, so an accepting run proves something', function (): void {
    $either = ($this->published)(new ClassT(Collection::class, [ScalarT::int(), $this->item]));

    // Either a list or an object, but of the item still.
    expect(($this->accepts)($either, (object) ['1' => (object) ['name' => 1]]))->toBeFalse()
        ->and(($this->accepts)($either, [(object) ['name' => 1]]))->toBeFalse()
        ->and(($this->accepts)($either, 'ada'))->toBeFalse();
});

it('publishes every key type as either shape, since none proves which', function (?array $args, array $expected): void {
    $item = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']];
    $schema = ($this->published)(new ClassT(Collection::class, $args ?? []));
    unset($schema['x-docuccino']);

    expect($schema)->toBe(array_map(static fn (mixed $v): mixed => $v === 'ITEM' ? $item : $v, $expected));
})->with([
    // An int key is no proof of a list: `filter()` keeps the keys of what it drops.
    'int' => [[ScalarT::int(), new ArrayShapeT([new ArrayShapeField('name', ScalarT::string())])], ['type' => ['array', 'object'], 'items' => 'ITEM', 'additionalProperties' => 'ITEM']],
    // Nor is a string key proof of an object: no rows are sent as `[]`, and numeric strings as ints.
    'string' => [[ScalarT::string(), new ArrayShapeT([new ArrayShapeField('name', ScalarT::string())])], ['type' => ['array', 'object'], 'items' => 'ITEM', 'additionalProperties' => 'ITEM']],
    'array-key' => [[UnionT::of([ScalarT::int(), ScalarT::string()]), new ArrayShapeT([new ArrayShapeField('name', ScalarT::string())])], ['type' => ['array', 'object'], 'items' => 'ITEM', 'additionalProperties' => 'ITEM']],
    // A key nothing could be read from may be either.
    'unknown' => [[new UnknownT('mixed'), new ArrayShapeT([new ArrayShapeField('name', ScalarT::string())])], ['type' => ['array', 'object'], 'items' => 'ITEM', 'additionalProperties' => 'ITEM']],
    // A collection with no generics says nothing about its keys or its items.
    'no generics' => [null, ['type' => ['array', 'object'], 'items' => [], 'additionalProperties' => []]],
]);

it('claims every framework collection and nothing else', function (string $fqcn, bool $claims): void {
    expect((new EnumerableTypeToSchema)->supports(new ClassT($fqcn)))->toBe($claims)
        ->and(FrameworkClasses::isCollection($fqcn))->toBe($claims);
})->with([
    'the contract' => [Enumerable::class, true],
    'the base collection' => [Collection::class, true],
    'Eloquent\'s collection' => [EloquentCollection::class, true],
    'the lazy collection' => [LazyCollection::class, true],
    'a JSON response' => [JsonResponse::class, false],
    'a class that does not exist' => ['App\\Missing\\Collection', false],
]);

it('declines a type that is not a framework collection', function (): void {
    $context = new SchemaConverter([], new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy);

    expect((new EnumerableTypeToSchema)->supports(ScalarT::string()))->toBeFalse()
        ->and((new EnumerableTypeToSchema)->toSchema(ScalarT::string(), $context))->toBeNull()
        ->and((new EnumerableTypeToSchema)->toSchema(new ClassT(JsonResponse::class), $context))->toBeNull();
});

it('documents a returned collection through the whole pipeline and every OpenAPI version', function (): void {
    $user = new ArrayShapeT([new ArrayShapeField('name', ScalarT::string())]);
    [$responses, , , $result] = documentForReturn(new ClassT(Collection::class, [UnionT::of([ScalarT::int(), ScalarT::string()]), $user]));
    $schema = $responses['200']['content']['application/json']['schema'];

    // The collection is the body, not a component reflected from the collection class.
    expect($schema['type'])->toBe(['array', 'object'])
        ->and($schema['items'])->toBe($schema['additionalProperties'])
        ->and($result->document->toArray()['components']['schemas'] ?? [])->not->toHaveKey('Collection');

    $emitted = [];
    foreach ([new OpenApi32Emitter, new OpenApi31DownlevelEmitter, new OpenApi30DownlevelEmitter] as $emitter) {
        $emitted[] = json_decode($emitter->emit($result->document), true);
    }

    // 3.1 and 3.2 carry the two types as written. 3.0 allows one, so it spells them as an anyOf, and OAS
    // 3.0.3 requires `items` beside `type: array` — so the items go into the array branch, and the object
    // branch keeps `additionalProperties`: a generator reads either type's items from its own branch.
    $at = static fn (array $document): array => $document['paths']['/api/forms']['get']['responses']['200']['content']['application/json']['schema'];
    $item = $at($emitted[0])['items'];
    expect($at($emitted[0])['type'])->toBe(['array', 'object'])
        ->and($at($emitted[1])['type'])->toBe(['array', 'object'])
        ->and($at($emitted[2]))->not->toHaveKey('type')
        ->and($at($emitted[2]))->not->toHaveKey('items')
        ->and($at($emitted[2]))->not->toHaveKey('additionalProperties')
        ->and($at($emitted[2])['anyOf'])->toBe([['type' => 'array', 'items' => $item], ['type' => 'object', 'additionalProperties' => $item]]);
});

it('documents a collection inside a JSON response as the body it sends', function (): void {
    $payload = new ClassT(Collection::class, [ScalarT::int(), ScalarT::string()]);
    [$either] = documentForReturn(new ClassT(FrameworkClasses::JSON_RESPONSE, [$payload]));
    $schema = static function (array $responses): array {
        $schema = $responses['200']['content']['application/json']['schema'];
        unset($schema['x-docuccino']);

        return $schema;
    };

    expect($schema($either))->toBe(['type' => ['array', 'object'], 'items' => ['type' => 'string'], 'additionalProperties' => ['type' => 'string']]);
});
