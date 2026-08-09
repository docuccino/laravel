<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
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
use Docuccino\Laravel\Tests\Fixtures\ApiResources\MultiShapeResource;

/**
 * ToArrayObject's multi-return-site merge (Wave C item 6) and nested-conditional recursion (item 7),
 * driven through JsonResourceSchema over a stubbed multi-site analysis. Mapper mechanics only; the
 * recovery half (the real engine producing several return sites / a nested MissingValue) is proven in
 * RealEngineIntegrationsTest's fixture-group cases.
 */
$missing = new ClassT(ResourceReflector::MISSING_VALUE);

/**
 * @param  list<ArrayShapeT>  $sites
 */
function mergeComponent(array $sites): array
{
    $engine = new StubTypeEngine(analyses: [
        MultiShapeResource::class.'::toArray' => new ActionAnalysis(
            returns: array_map(static fn (ArrayShapeT $s): ReturnSite => new ReturnSite($s, new SourceLocation('')), $sites),
        ),
    ]);
    $components = new ComponentRegistry;
    $converter = new SchemaConverter([new JsonResourceSchema, ...DefaultTypeMappers::all()], $engine, $components);
    $converter->toSchema(new ClassT(MultiShapeResource::class));

    return $components->schemas()['MultiShapeResource'];
}

it('merges the key union across return sites with per-rule required/optional', function () use ($missing): void {
    $siteA = new ArrayShapeT([
        new ArrayShapeField('id', ScalarT::int()),
        new ArrayShapeField('name', ScalarT::string()),
        new ArrayShapeField('role', ScalarT::string()),
        new ArrayShapeField('flag', UnionT::of([ScalarT::string(), $missing])),
    ]);
    $siteB = new ArrayShapeT([
        new ArrayShapeField('id', ScalarT::int()),
        new ArrayShapeField('name', ScalarT::string()),
        new ArrayShapeField('role', ScalarT::string(), optional: true),
        new ArrayShapeField('extra', ScalarT::string()),
    ]);

    $component = mergeComponent([$siteA, $siteB]);

    // Key union in first-seen order (site A then B's new keys).
    expect(array_keys($component['properties']))->toBe(['id', 'name', 'role', 'flag', 'extra']);
    // id/name present + non-optional in every site → required; role optional in B, flag a stripped
    // MissingValue conditional in A, extra absent from A → all optional.
    expect($component['required'])->toBe(['id', 'name']);
});

it('applies the merge rules per key', function (array $siteAType, array $siteBType, bool $required, array $schema): void {
    $siteA = new ArrayShapeT([new ArrayShapeField('id', ScalarT::int()), ...$siteAType]);
    $siteB = new ArrayShapeT([new ArrayShapeField('id', ScalarT::int()), ...$siteBType]);

    $component = mergeComponent([$siteA, $siteB]);

    expect(in_array('k', $component['required'] ?? [], true))->toBe($required)
        ->and($component['properties']['k'])->toBe($schema);
})->with([
    'present + same type in both → required, single schema' => [
        [new ArrayShapeField('k', ScalarT::string())],
        [new ArrayShapeField('k', ScalarT::string())],
        true,
        ['type' => 'string'],
    ],
    'absent from one site → optional' => [
        [new ArrayShapeField('k', ScalarT::string())],
        [],
        false,
        ['type' => 'string'],
    ],
    'optional (?key) in one site → optional' => [
        [new ArrayShapeField('k', ScalarT::string())],
        [new ArrayShapeField('k', ScalarT::string(), optional: true)],
        false,
        ['type' => 'string'],
    ],
    'conflicting types across sites → anyOf of distinct variants' => [
        [new ArrayShapeField('k', ScalarT::string())],
        [new ArrayShapeField('k', ScalarT::int())],
        true,
        ['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
    ],
]);

it('splices a merge() MergeValue array shape into the parent (item 5)', function (): void {
    $mergeShape = new ArrayShapeT([
        new ArrayShapeField('a', ScalarT::string()),
        new ArrayShapeField('b', ScalarT::int()),
    ]);
    $site = new ArrayShapeT([
        new ArrayShapeField('id', ScalarT::int()),
        // merge() sits at an int key with a MergeValue<array{a,b}> value.
        new ArrayShapeField(0, new ClassT('Illuminate\\Http\\Resources\\MergeValue', [$mergeShape])),
    ]);

    $component = mergeComponent([$site]);

    // The merged keys splice in beside id (no numeric "0" property); an unconditional merge keeps them
    // required.
    expect(array_keys($component['properties']))->toBe(['id', 'a', 'b'])
        ->and($component['properties'])->not->toHaveKey('0')
        ->and($component['required'])->toBe(['id', 'a', 'b']);
});

it('makes mergeWhen() spliced keys optional (item 5)', function () use ($missing): void {
    $mergeShape = new ArrayShapeT([new ArrayShapeField('a', ScalarT::string())]);
    $site = new ArrayShapeT([
        new ArrayShapeField('id', ScalarT::int()),
        // mergeWhen() is MergeValue<array{a}>|MissingValue when the condition may be falsy.
        new ArrayShapeField(0, UnionT::of([new ClassT('Illuminate\\Http\\Resources\\MergeValue', [$mergeShape]), $missing])),
    ]);

    $component = mergeComponent([$site]);

    expect(array_keys($component['properties']))->toBe(['id', 'a'])
        ->and($component['required'])->toBe(['id']);
});

it('skips an unshaped MergeValue rather than emitting a numeric key (item 5)', function (): void {
    $site = new ArrayShapeT([
        new ArrayShapeField('id', ScalarT::int()),
        // attributes()/dynamic value → MergeValue with no recoverable constant shape.
        new ArrayShapeField(0, new ClassT('Illuminate\\Http\\Resources\\MergeValue')),
    ]);

    $component = mergeComponent([$site]);

    expect(array_keys($component['properties']))->toBe(['id'])
        ->and($component['properties'])->not->toHaveKey('0');
});

it('recurses the conditional-strip through a nested object shape (item 7)', function () use ($missing): void {
    $nested = new ArrayShapeT([
        new ArrayShapeField('count', ScalarT::int()),
        new ArrayShapeField('tag', UnionT::of([ScalarT::string(), $missing])),
    ]);
    $site = new ArrayShapeT([
        new ArrayShapeField('id', ScalarT::int()),
        new ArrayShapeField('meta', $nested),
    ]);

    $component = mergeComponent([$site]);
    $meta = $component['properties']['meta'];

    // The nested MissingValue is stripped there too: `tag` recovers its string type and is optional,
    // so the nested object requires only `count` — the leak the flat strip left (audit item 15).
    expect($meta['type'])->toBe('object')
        ->and($meta['properties']['tag'])->toBe(['type' => 'string'])
        ->and($meta['required'])->toBe(['count']);
});
