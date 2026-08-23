<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\BuiltIn\EnumSchema;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\NullTypeEngine;
use Workbench\App\Enums\Season;
use Workbench\App\Enums\WidgetKind;
use Workbench\App\Enums\WidgetPriority;
use Workbench\App\Enums\WidgetStatus;
use Workbench\App\Enums\WidgetTier;

/**
 * Convert a type and hand back both the emitted schema and the components the conversion hoisted, so
 * the enum-component tests can assert the `$ref` at the use site AND the component body it points at.
 *
 * @param  array<string, mixed>  $representation
 * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>}
 */
function convertEnumFull(DType $type, array $representation = []): array
{
    $registry = new ComponentRegistry;
    $converter = new SchemaConverter(
        [new EnumSchema, ...DefaultTypeMappers::all()],
        new NullTypeEngine,
        $registry,
        RepresentationPolicy::fromConfig($representation),
    );

    return [$converter->toSchema($type)->schema, $registry->schemas()];
}

/**
 * @param  array<string, mixed>  $representation
 * @return array<string, mixed>
 */
function convertEnum(EnumT $enum, array $representation = []): array
{
    [$schema] = convertEnumFull($enum, $representation);

    return $schema;
}

it('hoists a backed enum to a $ref-ed component carrying its values and case descriptions', function (): void {
    [$schema, $components] = convertEnumFull(new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']));

    // Archived carries no prose, so the value-keyed map is withheld (Redoc hides values missing from
    // it) and the index-parallel array says the same thing with an empty-string gap.
    expect($schema)->toBe(['$ref' => '#/components/schemas/WidgetStatus'])
        ->and($components['WidgetStatus'])->toBe([
            'type' => 'string',
            'enum' => ['draft', 'published', 'archived'],
            'x-enum-descriptions' => ['Not yet visible to applicants.', 'Live and accepting traffic.', ''],
            'x-enum-varnames' => ['Draft', 'Published', 'Archived'],
            'x-enumNames' => ['Draft', 'Published', 'Archived'],
        ]);
});

it('inlines the enum schema byte-for-byte when the components policy is opted out', function (): void {
    [$schema, $components] = convertEnumFull(
        new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']),
        ['enums' => ['components' => false]],
    );

    expect($schema)->toBe([
        'type' => 'string',
        'enum' => ['draft', 'published', 'archived'],
        'x-enum-descriptions' => ['Not yet visible to applicants.', 'Live and accepting traffic.', ''],
        'x-enum-varnames' => ['Draft', 'Published', 'Archived'],
        'x-enumNames' => ['Draft', 'Published', 'Archived'],
    ])->and($components)->toBe([]);
});

it('dedupes one enum to a single component across many references', function (): void {
    $registry = new ComponentRegistry;
    $converter = new SchemaConverter(
        [new EnumSchema, ...DefaultTypeMappers::all()],
        new NullTypeEngine,
        $registry,
        new RepresentationPolicy,
    );

    $first = $converter->toSchema(new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']))->schema;
    $second = $converter->toSchema(new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']))->schema;

    expect($first)->toBe($second)
        ->and($first)->toBe(['$ref' => '#/components/schemas/WidgetStatus'])
        ->and(array_keys($registry->schemas()))->toBe(['WidgetStatus']);
});

it('falls back to a case docblock summary for the descriptions when no attribute is present', function (): void {
    [, $components] = convertEnumFull(new EnumT(Season::class, ['Summer', 'Winter', 'Spring']));

    expect($components['Season']['x-enum-descriptions'])->toBe([
        // Summer: docblock summary used (no attribute).
        'Warm and dry.',
        // Winter: the attribute wins over its docblock.
        'Cold and wet.',
        // Spring: neither attribute nor docblock → the index-parallel gap.
        '',
    ])
        // One case undescribed → the value-keyed map is withheld (its contract is completeness).
        ->and($components['Season'])->not->toHaveKey('x-enumDescriptions');
});

it('emits the value-keyed descriptions map when every case carries prose', function (): void {
    [, $components] = convertEnumFull(new EnumT(WidgetKind::class, ['Physical', 'Digital']));

    expect($components['WidgetKind']['x-enumDescriptions'])->toBe([
        'physical' => 'A shippable, tangible widget.',
        'digital' => 'A download; nothing ships.',
    ])->and($components['WidgetKind']['x-enum-descriptions'])->toBe([
        'A shippable, tangible widget.',
        'A download; nothing ships.',
    ]);
});

it('emits case names as x-enumNames on the component when the naming policy asks for them', function (): void {
    [$schema, $components] = convertEnumFull(
        new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']),
        ['enums' => ['naming' => 'x-enumNames']],
    );

    expect($schema)->toBe(['$ref' => '#/components/schemas/WidgetStatus'])
        ->and($components['WidgetStatus']['enum'])->toBe(['draft', 'published', 'archived'])
        ->and($components['WidgetStatus']['x-enumNames'])->toBe(['Draft', 'Published', 'Archived']);
});

it('documents an int-backed enum with an integer type and integer values', function (): void {
    [$schema, $components] = convertEnumFull(new EnumT(WidgetPriority::class, ['Low', 'Normal', 'High']));

    expect($schema)->toBe(['$ref' => '#/components/schemas/WidgetPriority'])
        ->and($components['WidgetPriority'])->toBe([
            'type' => 'integer',
            'enum' => [1, 5, 10],
            'x-enum-descriptions' => ['Handled when idle.', '', 'Jumps the queue.'],
            'x-enum-varnames' => ['Low', 'Normal', 'High'],
            'x-enumNames' => ['Low', 'Normal', 'High'],
        ]);
});

/**
 * `0,1,2` is the one backing run whose value-keyed map is a PHP LIST — PHP re-coerces the numeric-string
 * keys straight back to ints — so this is the enum that proves the map still emits as a JSON object.
 * The map's contract is completeness, and `["a","b","c"]` is not a map at all.
 */
it('emits the descriptions map as an object for a contiguous zero-based int-backed enum', function (): void {
    [, $components] = convertEnumFull(new EnumT(WidgetTier::class, ['Free', 'Standard', 'Premium']));

    $encoded = json_encode($components['WidgetTier']['x-enumDescriptions']);

    expect($encoded)->toBe('{"0":"No paid features.","1":"The paid default.","2":"Everything, plus support."}')
        ->and((array) $components['WidgetTier']['x-enumDescriptions'])->toBe([
            0 => 'No paid features.',
            1 => 'The paid default.',
            2 => 'Everything, plus support.',
        ]);
});

it('emits x-enum-varnames when the naming policy asks for that strategy', function (): void {
    [$schema, $components] = convertEnumFull(
        new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']),
        ['enums' => ['naming' => 'x-enum-varnames']],
    );

    expect($schema)->toBe(['$ref' => '#/components/schemas/WidgetStatus'])
        ->and($components['WidgetStatus']['x-enum-varnames'])->toBe(['Draft', 'Published', 'Archived'])
        ->and($components['WidgetStatus'])->not->toHaveKey('x-enumNames');
});

it('emits both hint spellings under the default naming strategy', function (): void {
    [, $components] = convertEnumFull(new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']));

    expect($components['WidgetStatus']['x-enum-varnames'])->toBe(['Draft', 'Published', 'Archived'])
        ->and($components['WidgetStatus']['x-enumNames'])->toBe(['Draft', 'Published', 'Archived']);
});

it('emits no name hints when the naming policy is opted out', function (): void {
    [, $components] = convertEnumFull(
        new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']),
        ['enums' => ['naming' => 'none']],
    );

    expect($components['WidgetStatus'])->not->toHaveKey('x-enumNames')
        ->and($components['WidgetStatus'])->not->toHaveKey('x-enum-varnames');
});

it('composes a nullable enum as anyOf[$ref, null] under both nullable policies', function (string $policy): void {
    $type = new UnionT([new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']), new NullT]);

    [$schema, $components] = convertEnumFull($type, ['nullable' => $policy]);

    // A $ref cannot carry `type: [x, null]`, so nullability is expressed as an explicit branch under
    // BOTH policies (type-array and anyof) — the honest composition.
    expect($schema)->toBe(['anyOf' => [['$ref' => '#/components/schemas/WidgetStatus'], ['type' => 'null']]])
        ->and($components)->toHaveKey('WidgetStatus');
})->with([
    'type-array policy' => ['type-array'],
    'anyof policy' => ['anyof'],
]);

it('inlines nullable enum composition when components are opted out (both policies)', function (string $policy, array $expected): void {
    $type = new UnionT([new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']), new NullT]);

    [$schema, $components] = convertEnumFull($type, ['nullable' => $policy, 'enums' => ['components' => false]]);

    expect($schema)->toBe($expected)->and($components)->toBe([]);
})->with([
    'type-array folds null into the type array' => [
        'type-array',
        [
            'type' => ['string', 'null'],
            'enum' => ['draft', 'published', 'archived'],
            'x-enum-descriptions' => ['Not yet visible to applicants.', 'Live and accepting traffic.', ''],
            'x-enum-varnames' => ['Draft', 'Published', 'Archived'],
            'x-enumNames' => ['Draft', 'Published', 'Archived'],
        ],
    ],
    'anyof expresses null as a branch' => [
        'anyof',
        [
            'anyOf' => [
                [
                    'type' => 'string',
                    'enum' => ['draft', 'published', 'archived'],
                    'x-enum-descriptions' => ['Not yet visible to applicants.', 'Live and accepting traffic.', ''],
                    'x-enum-varnames' => ['Draft', 'Published', 'Archived'],
                    'x-enumNames' => ['Draft', 'Published', 'Archived'],
                ],
                ['type' => 'null'],
            ],
        ],
    ],
]);

it('keeps an enum it cannot reflect inline (no honest name to hoist), falling back to case names', function (): void {
    [$schema, $components] = convertEnumFull(new EnumT('App\\Enums\\Missing', ['Open', 'Closed']));

    expect($schema)->toBe([
        'type' => 'string',
        'enum' => ['Open', 'Closed'],
        'x-enum-varnames' => ['Open', 'Closed'],
        'x-enumNames' => ['Open', 'Closed'],
    ])->and($components)->toBe([]);
});

it('degrades to a plain string schema when no values or case names are known', function (): void {
    // Neither reflectable nor carrying case names — the mapper still yields a valid (low-confidence)
    // string schema rather than an empty or broken one.
    $result = (new EnumSchema)->toSchema(
        new EnumT('App\\Enums\\Unknowable', []),
        new SchemaConverter([new EnumSchema, ...DefaultTypeMappers::all()], new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy),
    );

    expect($result->schema)->toBe(['type' => 'string'])
        ->and($result->confidence)->toBe(0.5);
});
