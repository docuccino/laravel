<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Extensions\EnumerableTypeToSchema;
use Docuccino\Laravel\Support\FrameworkClasses;

/**
 * A returned collection on the real engine: Larastan's collection stubs type each call, and the mapper
 * publishes the body from what the collection's model configures. What each body is sent as is proven
 * against the framework in `CollectionResponseContractTest` and `EloquentCollectionClassTest`; this proves
 * the types those tests assume are the ones the real engine recovers.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('recovers each returned collection as the class Larastan names, with its key type and its model', function (): void {
    $methods = ['listed', 'queried', 'plucked', 'mapped', 'filtered', 'filteredValues', 'sorted', 'sortedValues', 'held', 'keyedBySku', 'mappedBySku', 'keyedByAttribute', 'groupedByAttribute', 'jsonKeyedBySku', 'keyedModel'];
    $analyses = FixtureRunner::analyzeMany('app/Http/Controllers/KeyedCollectionController.php', 'App\\Http\\Controllers\\KeyedCollectionController', $methods);
    $converter = new SchemaConverter([new EnumerableTypeToSchema, ...DefaultTypeMappers::all()], new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy);

    $recovered = [];
    foreach ($methods as $method) {
        $type = ActionAnalysis::fromArray($analyses[$method])->returns[0]->type ?? null;
        // A JSON response sends its payload; the payload is the collection.
        if ($type instanceof ClassT && $type->fqcn === FrameworkClasses::JSON_RESPONSE) {
            $type = $type->typeArgs[0] ?? null;
        }
        expect($type instanceof ClassT && FrameworkClasses::isCollection($type->fqcn))->toBeTrue();
        assert($type instanceof ClassT);
        // Never a claim the key type alone would decide: a list or an object, or nothing.
        expect($converter->toSchema($type)->schema['type'] ?? null)->toBeIn([['array', 'object'], null]);

        $key = $type->typeArgs[0] ?? null;
        $item = $type->typeArgs[1] ?? null;
        $recovered[$method] = [
            str_contains($type->fqcn, 'Eloquent') ? 'eloquent' : 'base',
            $key instanceof ScalarT ? $key->scalar : ($key === null ? null : $key->toArray()['kind']),
            $item instanceof ClassT ? class_basename($item->fqcn) : ($item === null ? null : $item->toArray()['kind']),
        ];
    }

    expect($recovered)->toBe([
        // Int keys from a query and from every call that keeps or drops them: the type cannot tell which.
        'listed' => ['eloquent', 'int', 'Product'],
        'queried' => ['eloquent', 'int', 'Product'],
        'plucked' => ['base', 'union', 'unknown'],
        'mapped' => ['base', 'int', 'scalar'],
        'filtered' => ['eloquent', 'int', 'Product'],
        'filteredValues' => ['eloquent', 'int', 'Product'],
        'sorted' => ['eloquent', 'int', 'Product'],
        'sortedValues' => ['eloquent', 'int', 'Product'],
        // A parameter typed without generics says nothing of its model, so nothing is claimed of it.
        'held' => ['eloquent', null, null],
        'keyedBySku' => ['eloquent', 'string', 'Product'],
        'mappedBySku' => ['base', 'string', 'scalar'],
        'keyedByAttribute' => ['eloquent', 'union', 'Product'],
        'groupedByAttribute' => ['eloquent', 'union', 'Collection'],
        'jsonKeyedBySku' => ['eloquent', 'string', 'Product'],
        // Ledger's own `newCollection()` keys its rows, and Larastan names the framework's collection all
        // the same: the class the mapper must read is the model's, not this one (EloquentCollectionClassTest).
        'keyedModel' => ['eloquent', 'int', 'Ledger'],
    ]);
})->group('fixture');
