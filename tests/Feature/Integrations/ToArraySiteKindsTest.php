<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\CallableT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\ApiResources\JsonResourceSchema;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\MultiShapeResource;

/**
 * How every kind of return site weighs on the keys a resource method publishes as required. A key is
 * required only when every site the method can return from carries it, so a site whose keys cannot be
 * read — it may omit any of them — leaves nothing required and lowers confidence; a site that never
 * returns is no site. A site returning `[]` carries no key either, and Laravel sends it as the JSON
 * array `[]`, never `{}`, so the component must admit that too.
 */
$id = static fn (array $more = []): ArrayShapeT => new ArrayShapeT([new ArrayShapeField('id', ScalarT::int()), ...$more]);

// [the other site, `id` required, confidence lowered, `[]` admitted]
$sites = [
    'ArrayShapeT, keyed and carrying the key' => [$id(), true, false, false],
    'ArrayShapeT, empty — a `return []` carries no key and is sent as []' => [new ArrayShapeT([]), false, false, true],
    'ArrayShapeT, positional — a list has no keys to read' => [new ArrayShapeT([new ArrayShapeField(0, ScalarT::int())]), false, true, false],
    'UnionT of shapes each carrying the key — a ternary is one site per arm' => [UnionT::of([$id(), $id([new ArrayShapeField('x', ScalarT::string())])]), true, false, false],
    'UnionT with an arm that cannot be read' => [UnionT::of([$id(), new MapT(ScalarT::string(), new UnknownT('mixed'))]), false, true, false],
    'MapT' => [new MapT(ScalarT::string(), new UnknownT('mixed')), false, true, false],
    'ListT' => [new ListT(ScalarT::int()), false, true, false],
    'ClassT' => [new ClassT('Illuminate\\Support\\Collection'), false, true, false],
    'EnumT' => [new EnumT('App\\Enums\\Status'), false, true, false],
    'CallableT' => [new CallableT, false, true, false],
    'IntersectionT' => [new IntersectionT([new ClassT('Countable'), new ClassT('ArrayAccess')]), false, true, false],
    'LiteralT' => [new LiteralT('x'), false, true, false],
    'ScalarT' => [ScalarT::string(), false, true, false],
    'NullT' => [new NullT, false, true, false],
    'StatusMarkerT' => [new StatusMarkerT, false, true, false],
    'UnknownT' => [new UnknownT('unreadable'), false, true, false],
    'NeverT — a site that throws returns nothing' => [new NeverT, true, false, false],
    'VoidT' => [new VoidT, true, false, false],
];

it('requires a key only where every return site carries it', function (DType $other, bool $required, bool $lowered, bool $empty) use ($id): void {
    $location = new SourceLocation('');
    $engine = new StubTypeEngine(analyses: [
        MultiShapeResource::class.'::toArray' => new ActionAnalysis(returns: [new ReturnSite($id(), $location), new ReturnSite($other, $location)]),
    ]);
    $components = new ComponentRegistry;
    $result = (new SchemaConverter([new JsonResourceSchema, ...DefaultTypeMappers::all()], $engine, $components))
        ->toSchema(new ClassT(MultiShapeResource::class));
    $component = $components->schemas()['MultiShapeResource'];
    $object = $empty ? $component['anyOf'][0] : $component;

    expect(array_keys($object['properties']))->toContain('id')
        ->and(in_array('id', $object['required'] ?? [], true))->toBe($required)
        // A site that returns something unreadable is an imprecision the build records.
        ->and($result->confidence < 0.9)->toBe($lowered)
        ->and($component)->toBe($empty ? ['anyOf' => [$object, ['type' => 'array', 'maxItems' => 0]]] : $object);
})->with($sites);

it('lists a row for every kind of type a return site can have', function () use ($sites): void {
    $kinds = array_map(
        static fn (string $file): string => basename($file, '.php'),
        glob(dirname(__DIR__, 4).'/core/src/Inference/DType/*T.php') ?: [],
    );
    $listed = array_unique(array_map(static fn (string $row): string => strtok($row, ' ,'), array_keys($sites)));

    // A scan that found nothing would pass the diff below, so the directory must have been read.
    expect(count($kinds))->toBeGreaterThan(10)
        ->and(array_values(array_diff($kinds, $listed)))->toBe([]);
});
