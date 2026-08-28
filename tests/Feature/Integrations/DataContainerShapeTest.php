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
use Docuccino\Laravel\Integrations\Support\RuleParsing;
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
 * A `rules()` override, as the RuleSet the extension hands the builder. Tokens are read the way a real
 * override's are, so a row can state `max:100`.
 *
 * @param  array<string, list<string>>  $fields  rule TOKENS per field key
 */
function containerOverride(array $fields): RuleSet
{
    return new RuleSet(array_map(
        static fn (array $tokens): array => array_map(RuleParsing::token(...), $tokens),
        $fields,
    ));
}

/** `array<string, array<string, mixed>>` — a map whose values are themselves objects. */
function mapOfMaps(): MapT
{
    return new MapT(ScalarT::string(), new MapT(ScalarT::string(), new UnknownT('mixed')));
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
    // …and a positional shape, whose members differ per index, contributes no child path at all — so it
    // states `list`, the word that means a JSON array outright, rather than leaving `array` to be read as
    // the open question it is everywhere else.
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
    // An override naming another type has replaced the property's shape, not restated it.
    'another type' => [['settings' => ['string']], ['type' => 'string']],
    // Every other word the shape check composes: each one beside `array` NARROWS it — `list` to a JSON
    // array, `file`/`image` to an upload — so re-attaching open object values would contradict the
    // override rather than complete it.
    'array + list' => [['settings' => ['array', 'list']], ['type' => 'array']],
    'array + file' => [['settings' => ['array', 'file']], ['type' => 'string', 'format' => 'binary']],
    'array + image' => [['settings' => ['array', 'image']], ['type' => 'string', 'format' => 'binary', 'description' => 'An image file.']],
]);

it('keeps a recovered map an object when a rules() override names no type at all', function (): void {
    // An override stating nothing but a presence word contradicts nothing, so the recovered
    // `array<string, V>` is still all the information there is — the reading the class docblock gives
    // both carriers, and the opposite of the cells above, where the override named a shape of its own.
    expect(containerProperty('settings', new MapT(ScalarT::string(), ScalarT::int()), override: containerOverride(['settings' => ['sometimes']])))
        ->toBe(['type' => 'object', 'additionalProperties' => ['type' => 'integer']]);
});

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

/**
 * The container matrix: {recovered map, recovered list, nothing recovered} × {a `.*` override, none} ×
 * {a `rules()` override on the field, none}. Three facts per cell — which container keyword the field
 * publishes, where the `.*` constraints landed, and which size keyword the bound chose — because the
 * defect this pins was all three at once: a coarse rule token displacing the declared container.
 *
 * The rule that makes the map cells come out this way: `field.*` applies to every VALUE whatever the
 * keys are, so it carries no information about key type and never decides list-vs-map. The declared type
 * decides the container; `.*` constrains the value — `items` for a list, `additionalProperties` for a map.
 */
it('lets the declared container survive every rule vocabulary combination', function (string $property, DType $type, ?RuleSet $override, array $expected): void {
    expect(containerProperty($property, $type, override: $override))->toBe($expected);
})->with([
    // ── A recovered map. Its values are themselves maps, so the `.*` cells exercise the same rule one
    // level down: `array` on a value the type says is an object is the vaguer statement of the same thing.
    'map' => ['theme', mapOfMaps(), null, [
        'type' => 'object',
        'additionalProperties' => ['type' => 'object', 'additionalProperties' => []],
    ]],
    'map + field override' => ['theme', mapOfMaps(), containerOverride(['theme' => ['nullable', 'array', 'max:100']]), [
        'type' => ['object', 'null'],
        'additionalProperties' => ['type' => 'object', 'additionalProperties' => []],
        'maxProperties' => 100,
    ]],
    'map + `.*` override' => ['theme', mapOfMaps(), containerOverride(['theme.*' => ['array', 'max:500']]), [
        'type' => 'object',
        'additionalProperties' => ['type' => 'object', 'maxProperties' => 500],
    ]],
    'map + field and `.*` override' => ['theme', mapOfMaps(), containerOverride([
        'theme' => ['nullable', 'array', 'max:100'],
        'theme.*' => ['array', 'max:500'],
    ]), [
        'type' => ['object', 'null'],
        'additionalProperties' => ['type' => 'object', 'maxProperties' => 500],
        'maxProperties' => 100,
    ]],

    // ── A recovered list. Nothing here may move: the container it declares and the rule word an override
    // restates agree already, so the size keyword counts items and the `.*` constraints stay in `items`.
    'list' => ['tags', new ListT(ScalarT::string()), null, [
        'type' => 'array',
        'items' => ['type' => 'string'],
    ]],
    'list + field override' => ['tags', new ListT(ScalarT::string()), containerOverride(['tags' => ['array', 'max:100']]), [
        'type' => 'array',
        'maxItems' => 100,
        'items' => ['type' => 'string'],
    ]],
    'list + `.*` override' => ['tags', new ListT(ScalarT::string()), containerOverride(['tags.*' => ['string', 'max:9']]), [
        'type' => 'array',
        'items' => ['type' => 'string', 'maxLength' => 9, 'example' => 'example'],
    ]],
    'list + field and `.*` override' => ['tags', new ListT(ScalarT::string()), containerOverride([
        'tags' => ['array', 'max:100'],
        'tags.*' => ['string', 'max:9'],
    ]), [
        'type' => 'array',
        'maxItems' => 100,
        'items' => ['type' => 'string', 'maxLength' => 9, 'example' => 'example'],
    ]],

    // ── Nothing recovered. The negative the whole rule rests on: with no declared container to survive,
    // the `array` token IS all the information there is — and on its own it does not say WHICH container,
    // so the field publishes both and the bound is owed to each of them. The `.*` cells below have a
    // child key to read instead, which is what puts them back on one container.
    'no recovered type' => ['counters', new UnknownT('mixed'), null, []],
    'no recovered type + field override' => ['counters', new UnknownT('mixed'), containerOverride(['counters' => ['array', 'max:100']]), [
        'type' => ['array', 'object'],
        'maxItems' => 100,
        'maxProperties' => 100,
    ]],
    'no recovered type + `.*` override' => ['counters', new UnknownT('mixed'), containerOverride(['counters.*' => ['string', 'max:9']]), [
        'type' => 'array',
        'items' => ['type' => 'string', 'maxLength' => 9, 'example' => 'example'],
    ]],
    'no recovered type + field and `.*` override' => ['counters', new UnknownT('mixed'), containerOverride([
        'counters' => ['array', 'max:100'],
        'counters.*' => ['string', 'max:9'],
    ]), [
        'type' => 'array',
        'maxItems' => 100,
        'items' => ['type' => 'string', 'maxLength' => 9, 'example' => 'example'],
    ]],
]);

it('lets a `.*` override restate a map\'s values without restating its keys', function (): void {
    // The `.*` rules replace what inference said the values were — that is what an override does — while
    // the container they sit in is still the declared type's to state.
    expect(containerProperty('settings', new MapT(ScalarT::string(), ScalarT::int()), override: containerOverride([
        'settings' => ['array'],
        'settings.*' => ['string'],
    ])))->toBe(['type' => 'object', 'additionalProperties' => ['type' => 'string']]);
});

it('reads what the value schema says before trading a `.*` override\'s array word', function (DType $type, array $names, array $expected): void {
    // The plain case is the matrix cell above. These are the two edges of the same test: a value schema
    // saying `object` in a type LIST still says it, and a `.*` override stating more than `array` has
    // replaced the value shape, so there is nothing of the type's left to trade.
    expect(containerProperty('theme', $type, override: containerOverride([
        'theme' => ['array'],
        'theme.*' => $names,
    ])))->toBe($expected);
})->with([
    'nullable object values' => [
        new MapT(ScalarT::string(), UnionT::of([new MapT(ScalarT::string(), new UnknownT('mixed')), new NullT])),
        ['array'],
        ['type' => 'object', 'additionalProperties' => ['type' => 'object']],
    ],
    'array + list' => [
        mapOfMaps(),
        ['array', 'list'],
        ['type' => 'object', 'additionalProperties' => ['type' => 'array']],
    ],
]);

it('keeps a map and a list side by side under one rules() override', function (): void {
    // Both halves in one override, which is how one arrives: the map's container survives a restated
    // `array`, and the list's does not have to survive anything — the two answers come off the same rule
    // set, so neither may borrow the other's container.
    $metadata = new ClassMetadata(ContainerShapeData::class, [
        new PropertyMetadata('theme', UnionT::of([mapOfMaps(), new NullT])),
        new PropertyMetadata('tags', new ListT(ScalarT::string())),
    ]);
    $context = schemaConverter();

    $ruleSet = (new DataValidationRules)->build(
        ContainerShapeData::class,
        $metadata,
        new NullTypeEngine,
        containerOverride([
            'theme' => ['nullable', 'array', 'max:100'],
            'theme.*' => ['array', 'max:500'],
            'tags' => ['array'],
            'tags.*' => ['required', 'string', 'uuid'],
        ]),
        $context,
    );

    expect(validationSchema($ruleSet, $context)['properties'])->toBe([
        'theme' => [
            'type' => ['object', 'null'],
            'additionalProperties' => ['type' => 'object', 'maxProperties' => 500],
            'maxProperties' => 100,
        ],
        'tags' => [
            'type' => 'array',
            'items' => ['type' => 'string', 'format' => 'uuid', 'example' => '3fa85f64-5717-4562-b3fc-2c963f66afa6'],
        ],
    ]);
});

it('keeps a map an object when no converter is available to describe its values', function (): void {
    // The value schema is the type→schema chain's answer, so without one the map loses its values — but
    // not its container: `array<string, V>` is a JSON object whether or not anything can say what V is.
    // Publishing `type: array` here would be the one degradation that makes the field WRONG rather than
    // vague, and every legal request fail against it.
    expect(containerProperty('settings', new MapT(ScalarT::string(), ScalarT::string()), withConverter: false))
        ->toBe(['type' => 'object']);
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
