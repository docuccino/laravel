<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\TimelineData;

/**
 * A date-time is one MEMBER of whatever a property declares, not the whole of it. The Data mapper used to
 * answer the serialised date-time shape for any type that unioned one in, so a `?CarbonImmutable` published
 * `type: string` and forbade the null the API really sends — a schema that lies, and a generated client
 * that types the property non-nullable and fails at runtime. Every row below is a union arriving at that
 * producer; each has to come back out with all of its members.
 */
function timelineSchema(DType $published, ?string $example = null, string $nullable = 'type-array'): array
{
    $components = new ComponentRegistry;
    $engine = new StubTypeEngine(classes: [
        TimelineData::class => new ClassMetadata(TimelineData::class, [
            new PropertyMetadata('publishedAt', $published, example: $example),
        ]),
    ]);

    (new SchemaConverter(
        [new DataSchema(dateFormat: 'Y-m-d\TH:i:sP'), ...DefaultTypeMappers::all()],
        $engine,
        $components,
        new RepresentationPolicy(nullable: $nullable),
    ))->toSchema(new ClassT(TimelineData::class));

    return $components->schemas()['TimelineData']['properties']['publishedAt'];
}

it('keeps every member of a union that carries a date-time', function (DType $type, array $expected): void {
    expect(timelineSchema($type))->toBe($expected);
})->with([
    // The plain case, unchanged: a bare date-time IS the serialised string.
    'CarbonImmutable' => [
        new ClassT(CarbonImmutable::class),
        ['type' => 'string', 'format' => 'date-time'],
    ],

    // The reported defect. Nullability lives in the value's type union, and the union-aware conversion
    // was never reached, so the null arm was discarded.
    '?CarbonImmutable' => [
        UnionT::of([new ClassT(CarbonImmutable::class), new NullT]),
        ['type' => ['string', 'null'], 'format' => 'date-time'],
    ],

    // Broader than nullability: any other member was dropped too.
    'CarbonImmutable|int' => [
        UnionT::of([new ClassT(CarbonImmutable::class), ScalarT::int()]),
        ['anyOf' => [['type' => 'integer'], ['type' => 'string', 'format' => 'date-time']]],
    ],
    'CarbonImmutable|int|null' => [
        UnionT::of([new ClassT(CarbonImmutable::class), ScalarT::int(), new NullT]),
        ['anyOf' => [['type' => 'integer'], ['type' => 'string', 'format' => 'date-time'], ['type' => 'null']]],
    ],

    // Two date-time members serialise identically, so they collapse to one rather than publishing an
    // anyOf of two byte-identical branches.
    'CarbonImmutable|DateTimeImmutable' => [
        UnionT::of([new ClassT(CarbonImmutable::class), new ClassT(DateTimeImmutable::class)]),
        ['type' => 'string', 'format' => 'date-time'],
    ],
    '?CarbonImmutable|DateTimeImmutable' => [
        UnionT::of([new ClassT(CarbonImmutable::class), new ClassT(DateTimeImmutable::class), new NullT]),
        ['type' => ['string', 'null'], 'format' => 'date-time'],
    ],

    // The degradation row: no date-time member, so the producer never claims one.
    'a type with no date-time in it' => [ScalarT::string(), ['type' => 'string']],
    '?string' => [
        UnionT::of([ScalarT::string(), new NullT]),
        ['type' => ['string', 'null']],
    ],
]);

it('expresses a nullable date-time in the shape the document asked for', function (): void {
    // The `anyof` policy is why the assembly is core's and not this producer's: a special-cased member
    // that widens itself expresses nullability in a shape the rest of the document does not use.
    expect(timelineSchema(UnionT::of([new ClassT(CarbonImmutable::class), new NullT]), nullable: 'anyof'))
        ->toBe(['anyOf' => [['type' => 'string', 'format' => 'date-time'], ['type' => 'null']]]);
});

it('keeps the null on a timestamp cast to an integer too', function (DType $type, array $expected): void {
    // `expiresAt` carries `#[WithCast(DateTimeInterfaceCast::class, format: 'U')]`, so its wire TYPE is an
    // integer — which had the same hole: the cast answered for the whole union.
    $components = new ComponentRegistry;
    $engine = new StubTypeEngine(classes: [
        TimelineData::class => new ClassMetadata(TimelineData::class, [
            new PropertyMetadata('expiresAt', $type),
        ]),
    ]);

    (new SchemaConverter([new DataSchema, ...DefaultTypeMappers::all()], $engine, $components))
        ->toSchema(new ClassT(TimelineData::class));

    expect($components->schemas()['TimelineData']['properties']['expiresAt'])->toBe($expected);
})->with([
    'CarbonImmutable' => [
        new ClassT(CarbonImmutable::class),
        ['type' => 'integer', 'description' => 'Unix timestamp (seconds).'],
    ],
    '?CarbonImmutable' => [
        UnionT::of([new ClassT(CarbonImmutable::class), new NullT]),
        ['type' => ['integer', 'null'], 'description' => 'Unix timestamp (seconds).'],
    ],
]);

/*
 * An authored `@example null` on a nullable timestamp is read against the schema beside it, so the schema
 * being wrong made the example wrong: against `type: string` the four characters `null` are a perfectly
 * good string, and the document published the STRING "null" — which its own example lint then reported.
 * Fixing the schema is what corrects the example; nothing in the example reader changed for this.
 */
it('reads an authored example against the type the corrected schema states', function (
    DType $type,
    string $example,
    mixed $published,
): void {
    $schema = timelineSchema($type, $example);

    expect(array_key_exists('example', $schema))->toBeTrue()
        ->and($schema['example'])->toBe($published);
})->with([
    // A nullable schema states `null` before `string`, so the literal reads as the null it names.
    'null on a nullable timestamp is the null' => [
        UnionT::of([new ClassT(CarbonImmutable::class), new NullT]),
        'null',
        null,
    ],
    'an instant on a nullable timestamp is still the string' => [
        UnionT::of([new ClassT(CarbonImmutable::class), new NullT]),
        '2024-01-01T00:00:00+00:00',
        '2024-01-01T00:00:00+00:00',
    ],
    // The other side of the same reading, pinned rather than aspired to: a NON-null timestamp states
    // only `string`, and `null` is a perfectly good string there, so the four characters are published.
    // The property really is non-null, so the example is the author's to correct and nothing here can
    // tell that they meant the JSON null.
    'null on a non-null timestamp is the string' => [
        new ClassT(CarbonImmutable::class),
        'null',
        'null',
    ],
]);
