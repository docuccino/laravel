<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Core\TypeGrammar\TypeStringParser;
use Docuccino\Laravel\Integrations\SpatieData\DataRequestExtension;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ContainerShapeController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ContainerShapeData;

/**
 * The request side's answer to the rule vocabulary having one word — `array` — for every array shape.
 * A list synthesises the `key.*` item field Laravel writes by hand (the same trick the uploaded-file
 * list uses), a constant shape synthesises a `key.<member>` field per key, and a map, which Laravel has
 * no rule for at all, carries its value schema on an `additional_properties` rule.
 *
 * Mechanics only: the types are fed in as metadata. Their recovery from real source is proven against
 * the real engine in SpatieDataRealShapeTest.
 */

/**
 * One property's request schema, through the same normalise → order → convert sequence the extension
 * runs.
 *
 * @return array<string, mixed>
 */
function containerProperty(string $name, DType $type, bool $withConverter = true, ?RuleSet $override = null): array
{
    $metadata = new ClassMetadata(ContainerShapeData::class, [new PropertyMetadata($name, $type)]);
    $context = schemaConverter();

    $ruleSet = (new DataValidationRules)->build(
        ContainerShapeData::class,
        $metadata,
        new NullTypeEngine,
        $override,
        $withConverter ? $context : null,
    );

    return validationSchema($ruleSet, $context)['properties'][$name];
}

/**
 * A `rules()` override, as the RuleSet the extension hands the builder.
 *
 * @param  array<string, list<string>>  $fields  rule NAMES per field key
 */
function containerOverride(array $fields): RuleSet
{
    return new RuleSet(array_map(
        static fn (array $names): array => array_map(
            static fn (string $name): ValidationRule => ValidationRule::of($name),
            $names,
        ),
        $fields,
    ));
}

it('documents every recovered container shape', function (string $property, DType $type, array $expected): void {
    expect(containerProperty($property, $type))->toBe($expected);
})->with([
    // A map is a JSON OBJECT. `{"type": "array"}` is not merely vague here — a JSON object fails it.
    'array<string, mixed>' => ['settings', new MapT(ScalarT::string(), new UnknownT('mixed')), [
        'type' => 'object',
        'additionalProperties' => [],
    ]],
    'array<string, string>' => ['settings', new MapT(ScalarT::string(), ScalarT::string()), [
        'type' => 'object',
        'additionalProperties' => ['type' => 'string'],
    ]],
    'list<string>' => ['tags', new ListT(ScalarT::string()), [
        'type' => 'array',
        'items' => ['type' => 'string'],
    ]],
    'list<int>' => ['tags', new ListT(ScalarT::int()), [
        'type' => 'array',
        'items' => ['type' => 'integer'],
    ]],
    // Nested: the value schema comes off the same type→schema chain the response side uses, so both
    // sides describe `array<string, array<string, string|null>>` identically.
    'array<string, array<string, string|null>>' => ['theme', new MapT(ScalarT::string(), new MapT(ScalarT::string(), UnionT::of([ScalarT::string(), new NullT]))), [
        'type' => 'object',
        'additionalProperties' => [
            'type' => 'object',
            'additionalProperties' => ['type' => ['string', 'null']],
        ],
    ]],
    // A list of maps takes both paths at once: the item field is where the map's value schema lands.
    'list<array<string, int>>' => ['counters', new ListT(new MapT(ScalarT::string(), ScalarT::int())), [
        'type' => 'array',
        'items' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
    ]],
    'list<list<string>>' => ['tags', new ListT(new ListT(ScalarT::string())), [
        'type' => 'array',
        'items' => ['type' => 'array', 'items' => ['type' => 'string']],
    ]],
    'list<string|null>' => ['tags', new ListT(UnionT::of([ScalarT::string(), new NullT])), [
        'type' => 'array',
        'items' => ['type' => ['string', 'null']],
    ]],
    // A constant shape is an object with named members; an optional key stays out of `required`.
    'array{width: int, label?: string}' => ['box', new ArrayShapeT([
        new ArrayShapeField('width', ScalarT::int()),
        new ArrayShapeField('label', ScalarT::string(), optional: true),
    ]), [
        'type' => 'object',
        'properties' => [
            'width' => ['type' => 'integer'],
            'label' => ['type' => 'string'],
        ],
        'required' => ['width'],
    ]],
    // A shape whose member is itself a map keeps descending.
    'array{meta: array<string, string>}' => ['box', new ArrayShapeT([
        new ArrayShapeField('meta', new MapT(ScalarT::string(), ScalarT::string())),
    ]), [
        'type' => 'object',
        'properties' => ['meta' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
        'required' => ['meta'],
    ]],
    // Degradations. An element type nothing can say anything about still gets its item node, so the
    // request says the same `items: {}` the response side says for the same type…
    'list<mixed>' => ['tags', new ListT(new UnknownT('mixed')), ['type' => 'array', 'items' => []]],
    // …and a shape member nothing can be said about keeps its key and its requiredness, which is more
    // than the type says on its own — dropping it would document an optional member the shape requires.
    'array{meta: mixed}' => ['box', new ArrayShapeT([
        new ArrayShapeField('meta', new UnknownT('mixed')),
    ]), [
        'type' => 'object',
        'properties' => ['meta' => []],
        'required' => ['meta'],
    ]],
    // …and a positional shape, whose members differ per index, keeps the bare array rule.
    'array{0: int, 1: string}' => ['box', new ArrayShapeT([
        new ArrayShapeField(0, ScalarT::int()),
        new ArrayShapeField(1, ScalarT::string()),
    ]), ['type' => 'array']],
]);

it('keeps a positional shape an array all the way from the docblock that declared it', function (string $declared): void {
    // The stub above hands the shape in ready-made; this is the half that actually happens — the
    // grammar reads the tuple out of a `@param`/`@var` and has only the KEYS to go on. A shape whose
    // keys are the `0..n` sequence is a JSON array, so it must never synthesise `box.0`/`box.1` child
    // paths: those read as an object's property names and emit `properties` as a JSON ARRAY.
    $type = (new TypeStringParser)->parse($declared);

    expect($type)->toBeInstanceOf(ArrayShapeT::class)
        ->and($type->isList)->toBeTrue()
        ->and(containerProperty('box', $type))->toBe(['type' => 'array']);
})->with([
    'array{string, int}' => ['array{string, int}'],
    'array{0: string, 1: int}' => ['array{0: string, 1: int}'],
]);

it('documents a sparse int-keyed shape as the object PHP renders it', function (): void {
    // `array{1: string, 5: int}` is NOT a `0..n` sequence, so PHP renders it as a JSON object with
    // numeric-string keys — and the synthesised child paths are the honest answer for it.
    $type = (new TypeStringParser)->parse('array{1: string, 5: int}');

    expect($type)->toBeInstanceOf(ArrayShapeT::class)
        ->and($type->isList)->toBeFalse()
        ->and(containerProperty('box', $type))->toBe([
            'type' => 'object',
            'properties' => ['1' => ['type' => 'string'], '5' => ['type' => 'integer']],
            'required' => ['1', '5'],
        ]);
});

it('carries the spatie markers and nullability the property states', function (): void {
    // `array<string, mixed>|Optional` — the Optional marker is stripped, and makes the field optional.
    $extras = containerProperty('extras', new MapT(ScalarT::string(), new UnknownT('mixed')));
    expect($extras)->toBe(['type' => 'object', 'additionalProperties' => []]);

    // A nullable map is an object OR null, never an array.
    $nullable = containerProperty('settings', UnionT::of([new MapT(ScalarT::string(), ScalarT::int()), new NullT]));
    expect($nullable)->toBe(['type' => ['object', 'null'], 'additionalProperties' => ['type' => 'integer']]);
});

it('keeps a recovered map an object when a rules() override says only `array`', function (array $names, array $expected): void {
    // `array` is the rule vocabulary's ONE word for every array shape, so an override stating it says
    // nothing the recovered `array<string, mixed>` doesn't already say — and emitting `{"type": "array"}`
    // for it is not vague but WRONG: the JSON object the API accepts fails that schema. The value schema
    // rides on the field's own rule list (a list's `key.*` child rides on a key of its own and survives
    // the replacement already), so it is re-attached rather than lost with the rest of the inferred set.
    expect(containerProperty('settings', new MapT(ScalarT::string(), new UnknownT('mixed')), override: containerOverride(['settings' => $names])))
        ->toBe($expected);
})->with([
    "['array']" => [['array'], ['type' => 'object', 'additionalProperties' => []]],
    "['sometimes', 'array']" => [['sometimes', 'array'], ['type' => 'object', 'additionalProperties' => []]],
    "['required', 'array']" => [['required', 'array'], ['type' => 'object', 'additionalProperties' => []]],
    // The two forms an override is actually written in: a presence word beside the type word says nothing
    // about the shape, so the recovered values survive both.
    "['nullable', 'array']" => [['nullable', 'array'], ['type' => ['object', 'null'], 'additionalProperties' => []]],
    "['present', 'array']" => [['present', 'array'], ['type' => 'object', 'additionalProperties' => []]],
]);

it('lets an override that states a shape of its own replace the recovered map outright', function (array $fields, array $expected): void {
    expect(containerProperty('settings', new MapT(ScalarT::string(), ScalarT::int()), override: containerOverride($fields)))
        ->toBe($expected);
})->with([
    // A named child says which keys the object has, which is strictly more than "open values" — the
    // recovered map has nothing left to add.
    'named child' => [
        ['settings' => ['array'], 'settings.mode' => ['required', 'string']],
        ['type' => 'object', 'properties' => ['mode' => ['type' => 'string']], 'required' => ['mode']],
    ],
    // A `.*` child says the override means a JSON ARRAY; re-attaching object-ness would contradict it.
    'wildcard child' => [
        ['settings' => ['array'], 'settings.*' => ['string']],
        ['type' => 'array', 'items' => ['type' => 'string']],
    ],
    // An override naming another type has replaced the property's shape, not restated it.
    'another type' => [['settings' => ['string']], ['type' => 'string']],
    // Every other word the shape check composes: each one beside `array` NARROWS it — `list` to a JSON
    // array, `file`/`image` to an upload — so re-attaching open object values would contradict the
    // override rather than complete it.
    'array + list' => [['settings' => ['array', 'list']], ['type' => 'array']],
    'array + file' => [['settings' => ['array', 'file']], ['type' => 'string', 'format' => 'binary']],
    'array + image' => [['settings' => ['array', 'image']], ['type' => 'string', 'format' => 'binary', 'description' => 'An image file.']],
    // No type word at all: the override dropped the type the way it drops one for a scalar property too.
    'no type rule' => [['settings' => ['sometimes']], []],
]);

it('leaves a recovered list and shape alone under the same override', function (string $property, DType $type, array $expected): void {
    // The other half of the asymmetry: these state their structure on child KEYS, which an override
    // replacing the field's own key never touched in the first place.
    $override = new RuleSet([$property => [ValidationRule::of('sometimes'), ValidationRule::of('array')]]);

    expect(containerProperty($property, $type, override: $override))->toBe($expected);
})->with([
    'list<string>' => ['tags', new ListT(ScalarT::string()), ['type' => 'array', 'items' => ['type' => 'string']]],
    'array{width: int}' => ['box', new ArrayShapeT([new ArrayShapeField('width', ScalarT::int())]), [
        'type' => 'object',
        'properties' => ['width' => ['type' => 'integer']],
        'required' => ['width'],
    ]],
]);

it('degrades a map to the bare array rule when no converter is available', function (): void {
    // The value schema is the type→schema chain's answer, so without one the map falls back to the only
    // word the rule vocabulary has. Nothing else about the field changes.
    expect(containerProperty('settings', new MapT(ScalarT::string(), ScalarT::string()), withConverter: false))
        ->toBe(['type' => 'array']);
});

it('reaches the request body through the extension itself', function (): void {
    // The wiring half: the extension is what hands the rule builder the type→schema chain and normalises
    // the set it gets back, so the shapes above have to survive an actual handle() into a request body.
    $metadata = new ClassMetadata(ContainerShapeData::class, [
        new PropertyMetadata('settings', new MapT(ScalarT::string(), ScalarT::string())),
        new PropertyMetadata('tags', new ListT(ScalarT::string())),
    ]);
    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/container-shapes'),
        actionRef: new ActionRef('', ContainerShapeController::class, 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(classes: [ContainerShapeData::class => $metadata]),
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            ruleTransformers: ValidationIntegration::transformers(),
        ),
    );

    (new DataRequestExtension)->handle($operation = new OperationDraft, $context);

    $body = $operation->freeze()->toArray()['requestBody'] ?? [];
    expect($body['content']['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/ContainerShapeData']);

    $component = $context->components->schemas()['ContainerShapeData'];
    expect($component['properties']['settings'])->toBe(['type' => 'object', 'additionalProperties' => ['type' => 'string']])
        ->and($component['properties']['tags'])->toBe(['type' => 'array', 'items' => ['type' => 'string']]);
});

it('descends a deep container all the way down', function (): void {
    // The descent needs no cap of its own: a DType is a finite acyclic tree, and the engine's own
    // translation budget stops long before this. A cap here could only ever truncate a legitimate type.
    $deep = new ListT(new ListT(new ListT(new ListT(new ListT(ScalarT::string())))));

    expect(containerProperty('tags', $deep))->toBe([
        'type' => 'array',
        'items' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]]]],
    ]);
});
