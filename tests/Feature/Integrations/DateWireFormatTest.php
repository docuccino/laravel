<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataRequestExtension;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Integrations\Support\DateWireFormat;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\DateLadderController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\DateLadderData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\DateOverrideData;
use Illuminate\Routing\Router;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Optional;

/**
 * What a date-typed property publishes, in BOTH directions, from one class. The invariant: one value is
 * documented one way. A `date` rule is one word for everything non-relative `strtotime` parses, so it is
 * narrower than the server accepts and never the source a date-typed property's format comes from — and a
 * client round-tripping a value documented `date` on the way in and `date-time` on the way out truncates
 * the time.
 *
 * Both sides are asserted from one class here, and the shape that reaches each is the *whole* leaf
 * schema, so neither direction can move without the other being seen to.
 */
function ladderMetadata(): ClassMetadata
{
    $carbon = new ClassT(CarbonImmutable::class);
    $nullableCarbon = new UnionT([$carbon, new NullT]);

    return new ClassMetadata(DateLadderData::class, [
        new PropertyMetadata('statedFormat', $carbon),
        new PropertyMetadata('castTimestamp', $carbon),
        new PropertyMetadata('castDateOnly', $carbon),
        new PropertyMetadata('castBespoke', $carbon),
        new PropertyMetadata('declaredOnly', $carbon),
        new PropertyMetadata('nullableDeclared', $nullableCarbon),
        new PropertyMetadata('afterLiteral', $carbon),
        new PropertyMetadata('bareDateRule', ScalarT::string()),
        new PropertyMetadata('declaredWithDateRule', new UnionT([new ClassT(Optional::class), $carbon, new NullT])),
    ]);
}

/**
 * The REQUEST body's leaf schemas for a Data class, through the real recovery path.
 *
 * @param  array<string, ClassMetadata>  $classes
 * @return array<string, mixed>
 */
function dateWireRequest(string $fqcn, string $controller, array $classes, string $dateFormat = DateWireFormat::DEFAULT_FORMAT): array
{
    $components = new ComponentRegistry;
    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/dates'),
        actionRef: new ActionRef('', $controller, 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(classes: $classes),
        document: new DocumentConfig('default', []),
        components: $components,
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            ruleTransformers: ValidationIntegration::transformers(),
        ),
    );

    (new DataRequestExtension(new DataValidationRules(dateFormat: $dateFormat)))->handle(new OperationDraft, $context);

    /** @var array<string, mixed> $properties */
    $properties = $components->schemas()[class_basename($fqcn)]['properties'] ?? [];

    return $properties;
}

/**
 * The same class's RESPONSE component, for the side that always derived its format from the config.
 *
 * @param  array<string, ClassMetadata>  $classes
 * @return array<string, mixed>
 */
function dateWireResponse(string $fqcn, array $classes, string $dateFormat = DateWireFormat::DEFAULT_FORMAT): array
{
    $components = new ComponentRegistry;
    $converter = new SchemaConverter(
        [new DataSchema(dateFormat: $dateFormat), ...DefaultTypeMappers::all()],
        new StubTypeEngine(classes: $classes),
        $components,
    );
    $converter->toSchema(new ClassT($fqcn));

    /** @var array<string, mixed> $properties */
    $properties = $components->schemas()[class_basename($fqcn)]['properties'] ?? [];

    return $properties;
}

/**
 * One property's type/format pair on each side, which is all the two directions can contradict each
 * other about.
 *
 * @return array{request: array<string, mixed>, response: array<string, mixed>}
 */
function dateWireShapes(string $property, string $dateFormat = DateWireFormat::DEFAULT_FORMAT): array
{
    $classes = [DateLadderData::class => ladderMetadata()];
    $pick = static function (array $properties) use ($property): array {
        /** @var array<string, mixed> $schema */
        $schema = $properties[$property] ?? [];

        return ['type' => $schema['type'] ?? null, 'format' => $schema['format'] ?? null];
    };

    return [
        'request' => $pick(dateWireRequest(DateLadderData::class, DateLadderController::class, $classes, $dateFormat)),
        'response' => $pick(dateWireResponse(DateLadderData::class, $classes, $dateFormat)),
    ];
}

/**
 * The ladder, most specific source first. Each row pins BOTH directions, so a row where the app states
 * nothing per-property and the two columns differ is the defect coming back.
 */
it('resolves a date property\'s format from its most specific source, both ways', function (string $property, array $request, array $response): void {
    $shapes = dateWireShapes($property);

    expect($shapes['request'])->toBe($request)
        ->and($shapes['response'])->toBe($response);
})->with([
    // 1. `date_format:d/m/Y` — the app states the accepted wire format outright, and nothing displaces
    //    it. No `format` word describes a `d/m/Y` value, so the request claims none and names the pattern
    //    instead (asserted whole below). The response emits its own configured format, so the two honestly
    //    differ: this app parses `d/m/Y` in and writes ATOM out.
    'a date_format rule wins' => ['statedFormat',
        ['type' => 'string', 'format' => null],
        ['type' => 'string', 'format' => 'date-time'],
    ],

    // 2. The `DateTimeInterfaceCast` format is what the cast really parses input with. `U` is the one
    //    shape that is not a string at all, and both sides say integer.
    'a U cast is a timestamp on both sides' => ['castTimestamp',
        ['type' => 'integer', 'format' => null],
        ['type' => 'integer', 'format' => null],
    ],
    'a date-only cast beats the configured format' => ['castDateOnly',
        ['type' => 'string', 'format' => 'date'],
        ['type' => 'string', 'format' => 'date-time'],
    ],
    // A cast format no keyword names: the request says string and names the pattern rather than claiming
    // the `date` a `d/m/Y` value fails.
    'a bespoke cast format claims no format' => ['castBespoke',
        ['type' => 'string', 'format' => null],
        ['type' => 'string', 'format' => 'date-time'],
    ],

    // 3. The declared type with no rule stating otherwise — one config value, so one answer both ways.
    //    The request is owed the format here too: the declared type is the only source there is.
    'the declared type alone' => ['declaredOnly',
        ['type' => 'string', 'format' => 'date-time'],
        ['type' => 'string', 'format' => 'date-time'],
    ],
    'a nullable declared type keeps its null arm' => ['nullableDeclared',
        ['type' => ['string', 'null'], 'format' => 'date-time'],
        ['type' => ['string', 'null'], 'format' => 'date-time'],
    ],
    // The reported shape: an Optional marker stripped, a nullable union, and a `date` rule beside it.
    'the declared type beats a bare date rule' => ['declaredWithDateRule',
        ['type' => ['string', 'null'], 'format' => 'date-time'],
        ['type' => ['string', 'null'], 'format' => 'date-time'],
    ],

    // 4. A comparison bound is described, and the declared type still names the format.
    'a comparison target leaves the declared format standing' => ['afterLiteral',
        ['type' => 'string', 'format' => 'date-time'],
        ['type' => 'string', 'format' => 'date-time'],
    ],

    // 5. The control, pinned deliberately: a `date` rule with no date type behind it. Nothing better is
    //    known, so `date` remains the reading of intent — this row must not be "fixed".
    'a bare date rule with no date type still publishes date' => ['bareDateRule',
        ['type' => 'string', 'format' => 'date'],
        ['type' => 'string', 'format' => null],
    ],
]);

it('derives both directions from the one configured format', function (): void {
    // The guard against a second guess creeping back in: every property whose format has no
    // per-property source — no `date_format` rule, no cast — must read identically both ways, whatever
    // `data.date_format` says. Read off the fixture rather than listed, so a property added to it is
    // covered without a line here.
    $properties = [];
    foreach ((new ReflectionClass(DateLadderData::class))->getConstructor()->getParameters() as $parameter) {
        $stated = $parameter->getAttributes(DateFormat::class) !== [] || $parameter->getAttributes(WithCast::class) !== [];
        if (! $stated && dateWireCarriesDate($parameter->getType())) {
            $properties[] = $parameter->getName();
        }
    }

    // A scan that stopped seeing its shapes must fail rather than pass: the fixture's four
    // symmetric date properties are the population, and a property added to it lands here.
    expect($properties)->toHaveCount(4);

    // The last two rows are the formats no keyword names: `d/m/Y H:i` is a perfectly ordinary
    // `data.date_format`, and `Y-m-d\T` is the escaped-literal trap a character-class reading of the
    // pattern gets wrong. Both sides owe a string that claims nothing rather than a format the value fails.
    $configurations = [
        'Y-m-d\TH:i:sP' => 'date-time',
        'Y-m-d' => 'date',
        'd/m/Y H:i' => null,
        'Y-m-d\T' => null,
    ];

    foreach ($properties as $property) {
        foreach ($configurations as $configured => $expected) {
            $shapes = dateWireShapes($property, (string) $configured);

            $where = $property.' @ '.$configured;
            expect($shapes['request'])->toBe($shapes['response'], $where)
                ->and($shapes['request']['format'])->toBe($expected, $where);
        }
    }
});

it('names the pattern where no format word describes it, in both directions', function (): void {
    // The whole leaf, both ways, for a `data.date_format` nothing names: what the endpoint accepts is the
    // pattern's own bytes, so the request carries them as the example rather than an ISO value the server
    // would 422 — and neither side publishes a `format` its own values fail.
    $classes = [DateLadderData::class => ladderMetadata()];
    $configured = 'd/m/Y H:i';

    expect(dateWireRequest(DateLadderData::class, DateLadderController::class, $classes, $configured)['declaredOnly'])
        ->toBe([
            'type' => 'string',
            'description' => 'Expected format: d/m/Y H:i',
            'example' => '01/01/2024 00:00',
        ])
        ->and(dateWireResponse(DateLadderData::class, $classes, $configured)['declaredOnly'])
        ->toBe([
            'type' => 'string',
            'description' => 'Serialized using the date format "d/m/Y H:i".',
        ]);
});

it('documents a bespoke cast format as the value the cast really parses', function (): void {
    // The reported shape: a `#[WithCast(DateTimeInterfaceCast::class, format: 'd/m/Y')]` property. A
    // `format: date` here would be false twice over — the claim, and the ISO example synthesised from it.
    $request = dateWireRequest(
        DateLadderData::class,
        DateLadderController::class,
        [DateLadderData::class => ladderMetadata()],
    );

    expect($request['castBespoke'])->toBe([
        'type' => 'string',
        'description' => 'Expected format: d/m/Y',
        'example' => '01/01/2024',
    ])
        // The rule the app states outright is answered the same way, by the same policy.
        ->and($request['statedFormat'])->toBe([
            'type' => 'string',
            'description' => 'Expected format: d/m/Y',
            'example' => '01/01/2024',
        ]);
});

/** Whether a promoted parameter's declared type is, or unions in, a `DateTimeInterface`. */
function dateWireCarriesDate(?ReflectionType $type): bool
{
    $members = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
    foreach ($members as $member) {
        if ($member instanceof ReflectionNamedType && ! $member->isBuiltin() && is_a($member->getName(), DateTimeInterface::class, true)) {
            return true;
        }
    }

    return false;
}

it('keeps the recovered wire format under a rules() override that says less', function (): void {
    // A `rules()` override replaces the inferred rules at its key, but the wire format is a fact about
    // the property's TYPE, not about its rules — so restating the bare `date` word, or naming no type at
    // all, has not restated it. Stating `date_format` has.
    $carbon = new ClassT(CarbonImmutable::class);
    $metadata = new ClassMetadata(DateOverrideData::class, [
        new PropertyMetadata('restatedDate', $carbon),
        new PropertyMetadata('statedFormat', $carbon),
        new PropertyMetadata('noTypeStated', $carbon),
    ]);
    $engine = new StubTypeEngine(classes: [DateOverrideData::class => $metadata]);
    $converter = new SchemaConverter(DefaultTypeMappers::all(), $engine, new ComponentRegistry);
    $override = new RuleSet([
        'restatedDate' => [ValidationRule::of('required'), ValidationRule::of('date')],
        'statedFormat' => [ValidationRule::of('required'), ValidationRule::of('date_format', ['d/m/Y'])],
        'noTypeStated' => [ValidationRule::of('required')],
    ]);

    $rules = (new DataValidationRules)->build(DateOverrideData::class, $metadata, $engine, $override, $converter);
    $schema = (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))
        ->convert((new RuleOrdering)->order((new RuleSetNormalizer)->normalize($rules)), $converter)->schema;

    expect($schema['properties']['restatedDate']['format'])->toBe('date-time')
        ->and($schema['properties']['statedFormat'])->not->toHaveKey('format')
        ->and($schema['properties']['statedFormat']['description'])->toBe('Expected format: d/m/Y')
        ->and($schema['properties']['statedFormat']['example'])->toBe('01/01/2024')
        ->and($schema['properties']['noTypeStated']['format'])->toBe('date-time');
});

it('describes a Unix-timestamp property identically in both directions', function (): void {
    // The one date shape that is not a string: the integer says it, and the coarse rule's `format` goes
    // with the type it belonged to rather than lingering on an integer.
    $classes = [DateLadderData::class => ladderMetadata()];

    expect(dateWireRequest(DateLadderData::class, DateLadderController::class, $classes)['castTimestamp'])
        ->toBe(['type' => 'integer', 'description' => 'Unix timestamp (seconds).'])
        ->and(dateWireResponse(DateLadderData::class, $classes)['castTimestamp'])
        ->toBe(['type' => 'integer', 'description' => 'Unix timestamp (seconds).']);
});

it('claims a format only where the pattern\'s own bytes satisfy it', function (): void {
    // The allow-list per entry. A claim is honest exactly when the value the pattern writes validates
    // against the keyword claimed for it, and {@see FieldExample} runs that check before publishing an
    // example — so an entry whose own bytes its format rejects publishes no example and fails here.
    $formats = DateWireFormat::isoFormats();

    // Read off the table rather than listed: an entry added there is covered without a line here, and a
    // table that emptied fails instead of passing vacuously.
    expect($formats)->toHaveCount(5);

    foreach ($formats as $format) {
        $schema = (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))
            ->convert(new RuleSet(['f' => [ValidationRule::of('date_wire', [$format])]]), schemaConverter())->schema;

        expect($schema['properties']['f'])->toBe([
            'type' => 'string',
            'format' => DateWireFormat::oas($format),
            'example' => DateWireFormat::example($format),
        ], $format);
    }

    // Everything else: no keyword names it, whatever tokens it happens to contain.
    expect(DateWireFormat::oas('Y-m-d H:i:s'))->toBeNull()
        ->and(DateWireFormat::oas('d/m/Y'))->toBeNull()
        ->and(DateWireFormat::oas('Y-m-d\T'))->toBeNull()
        ->and(DateWireFormat::oas(DateWireFormat::UNIX))->toBeNull();
});

it('breaks the rank-12 tie on insertion order alone', function (): void {
    // `additional_properties` and `date_wire` share a rank, so the sort tie-breaks on position — a
    // function of the synthesising code, never of route order. Pinned in both directions: a sort that
    // stopped being stable would reorder one of them.
    $map = ValidationRule::of('additional_properties', ['{"type":"string"}']);
    $date = ValidationRule::of('date_wire', ['Y-m-d']);
    $names = static fn (array $rules): array => array_map(
        static fn (ValidationRule $rule): string => $rule->name,
        (new RuleOrdering)->order(new RuleSet(['f' => $rules]))->fields['f'],
    );

    expect($names([$map, $date]))->toBe(['additional_properties', 'date_wire'])
        ->and($names([$date, $map]))->toBe(['date_wire', 'additional_properties']);
});

it('documents the format the app configured, through the container', function (): void {
    // The bind that hands the app's `data.date_format` to both sides. Every other test here constructs the
    // recovery objects itself, so without this one the whole suite stays green while an app's request
    // bodies and responses both document the package default.
    config(['data.date_format' => 'Y-m-d']);

    $document = generateDocumentOverDateRoute();

    $request = resolveSchema($document, $document['paths']['/api/dates']['post']['requestBody']['content']['application/json']['schema'] ?? []);
    // A POST returning the Data class it took: spatie's own 201.
    $response = resolveSchema($document, $document['paths']['/api/dates']['post']['responses']['201']['content']['application/json']['schema'] ?? []);

    expect($request['properties']['declaredOnly'] ?? null)->toBe(['type' => 'string', 'format' => 'date', 'example' => '2024-01-01'])
        ->and($response['properties']['declaredOnly'] ?? null)->toBe(['type' => 'string', 'format' => 'date']);
});

/**
 * The emitted `default` document with one route over the date ladder, built through the container so the
 * real bindings — not a hand-constructed recovery object — decide what a date property publishes.
 *
 * @return array<string, mixed>
 */
function generateDocumentOverDateRoute(): array
{
    $result = localityBuild(
        static fn (Router $router): mixed => $router->post('api/dates', [DateLadderController::class, 'store']),
        static fn (): StubTypeEngine => new StubTypeEngine(
            analyses: [DateLadderController::class.'::store' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT(DateLadderData::class), new SourceLocation(''))],
            )],
            classes: [DateLadderData::class => ladderMetadata()],
        ),
    );

    return emittedArray($result);
}
