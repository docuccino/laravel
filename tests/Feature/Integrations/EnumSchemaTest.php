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
use Workbench\App\Enums\WidgetPriority;
use Workbench\App\Enums\WidgetStatus;

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

    expect($schema)->toBe(['$ref' => '#/components/schemas/WidgetStatus'])
        ->and($components['WidgetStatus'])->toBe([
            'type' => 'string',
            'enum' => ['draft', 'published', 'archived'],
            'x-enumDescriptions' => [
                'draft' => 'Not yet visible to applicants.',
                'published' => 'Live and accepting traffic.',
            ],
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
        'x-enumDescriptions' => [
            'draft' => 'Not yet visible to applicants.',
            'published' => 'Live and accepting traffic.',
        ],
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

it('falls back to a case docblock summary for x-enumDescriptions when no attribute is present', function (): void {
    [, $components] = convertEnumFull(new EnumT(Season::class, ['Summer', 'Winter', 'Spring']));

    expect($components['Season']['x-enumDescriptions'])->toBe([
        // Summer: docblock summary used (no attribute).
        'summer' => 'Warm and dry.',
        // Winter: the attribute wins over its docblock.
        'winter' => 'Cold and wet.',
        // Spring: neither attribute nor docblock → omitted.
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
            'x-enumDescriptions' => [
                '1' => 'Handled when idle.',
                '10' => 'Jumps the queue.',
            ],
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

it('emits no name hints under the default (none) naming strategy', function (): void {
    [, $components] = convertEnumFull(new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']));

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
            'x-enumDescriptions' => [
                'draft' => 'Not yet visible to applicants.',
                'published' => 'Live and accepting traffic.',
            ],
        ],
    ],
    'anyof expresses null as a branch' => [
        'anyof',
        [
            'anyOf' => [
                [
                    'type' => 'string',
                    'enum' => ['draft', 'published', 'archived'],
                    'x-enumDescriptions' => [
                        'draft' => 'Not yet visible to applicants.',
                        'published' => 'Live and accepting traffic.',
                    ],
                ],
                ['type' => 'null'],
            ],
        ],
    ],
]);

it('keeps an enum it cannot reflect inline (no honest name to hoist), falling back to case names', function (): void {
    [$schema, $components] = convertEnumFull(new EnumT('App\\Enums\\Missing', ['Open', 'Closed']));

    expect($schema)->toBe(['type' => 'string', 'enum' => ['Open', 'Closed']])
        ->and($components)->toBe([]);
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
