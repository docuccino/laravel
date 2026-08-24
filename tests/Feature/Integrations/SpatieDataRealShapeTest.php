<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ActionPreviewData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\MergedRulesData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\MfaChallengeData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\MockedSnapshotData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\SaveAnswersData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\SnapshotData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\UpdateNodeData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\UploadPolicyData;

/**
 * What the real engine's recovery — and its gaps — actually emit for a spatie Data class. Every type and
 * rule here comes from the fixture app through the real engine; only the class the mapper reflects is a
 * loadable in-process twin, since the mapper's guards reflect the FQCN they are handed.
 *
 * Should a shape below turn out to be wrong, pin it as DEGRADED with the gap named rather than quietly
 * correcting the expectation — a gap discovered in a published document is what this file prevents.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The real engine's metadata for a fixture-app class, re-keyed onto a loadable in-process twin. */
function realMetadataAs(string $fixtureFqcn, string $twinFqcn): ClassMetadata
{
    $real = ClassMetadata::fromArray(FixtureRunner::classMetadata($fixtureFqcn));

    return new ClassMetadata($twinFqcn, $real->properties, $real->summary);
}

/**
 * `[all hoisted components, the twin's own]` from the Data mapper, over the real engine's types. A nested
 * class the recovered types reference keeps its fixture-app FQCN — nothing in-process can load it — so its
 * real metadata is seeded under that name, which is what lets it hoist as a component of its own.
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function realDataComponent(string $fixtureFqcn, string $twinFqcn, string ...$nested): array
{
    $classes = [$twinFqcn => realMetadataAs($fixtureFqcn, $twinFqcn)];
    foreach ($nested as $nestedFqcn) {
        $classes[$nestedFqcn] = ClassMetadata::fromArray(FixtureRunner::classMetadata($nestedFqcn));
    }

    $engine = new StubTypeEngine(classes: $classes);
    $components = new ComponentRegistry;
    $converter = new SchemaConverter([new DataSchema, ...DefaultTypeMappers::all()], $engine, $components);
    $converter->toSchema(new ClassT($twinFqcn));

    $schemas = $components->schemas();

    return [$schemas, $schemas[substr((string) strrchr($twinFqcn, '\\'), 1)]];
}

/**
 * One Data class's request schema, over the real engine's metadata.
 *
 * @return array<string, mixed>
 */
function realRequestSchema(string $twinFqcn, ClassMetadata $metadata, ?RuleSet $override = null): array
{
    $context = schemaConverter();

    return validationSchema(
        (new DataValidationRules)->build($twinFqcn, $metadata, new NullTypeEngine, $override, $context),
        $context,
    );
}

/** A traced `rules()` override from the fixture app, as the RuleSet the extension would pass on. */
function tracedOverride(string $relPath, string $fixtureFqcn): RuleSet
{
    $trace = FixtureRunner::traceRules($relPath, $fixtureFqcn, 'rules');

    return new RuleSet(array_map(
        static fn (array $rules): array => array_map(
            static fn (array $rule): ValidationRule => new ValidationRule($rule['name'], $rule['parameters'], $rule['note'] ?? null),
            $rules,
        ),
        $trace['fields'],
    ));
}

it('emits a constructor-@param map as an object with additionalProperties', function (): void {
    // `context` is the one array member of the fixture app's SnapshotData whose generic is written in the
    // constructor `@param` block rather than in its own `@var`; both forms have to land the same way.
    [, $component] = realDataComponent('App\\Data\\SnapshotData', SnapshotData::class, 'App\\Data\\SnapshotFormData');

    expect($component['properties']['context'])->toBe([
        'type' => 'object',
        'additionalProperties' => [],
        'description' => 'Inline request context as it stood at submit.',
    ]);
})->group('fixture');

it('emits the full shape for every array member typed in its own @var', function (string $property, array $expected): void {
    // Each of these carries its shape from its own `@var`, alongside the prose in the same docblock.
    [, $component] = realDataComponent('App\\Data\\SnapshotData', SnapshotData::class, 'App\\Data\\SnapshotFormData');

    expect($component['properties'][$property])->toBe($expected);
})->with([
    '@var array<string, mixed>' => ['candidate', [
        'type' => 'object',
        'additionalProperties' => [],
        'description' => "Inline candidate profile state as it stood at submit: identity, contact details and whatever\nelse the tenant's profile schema carried.",
    ]],
    '@var array<string, array<string, string|null>>' => ['theme_data', [
        'type' => 'object',
        'additionalProperties' => [
            'type' => 'object',
            'additionalProperties' => ['type' => ['string', 'null']],
        ],
        'description' => 'Theme colour and typography values, keyed by mode then by token.',
    ]],
    '@var list<SnapshotFormData>' => ['forms', [
        'type' => 'array',
        'items' => ['$ref' => '#/components/schemas/SnapshotFormData'],
        'description' => "One entry per form zone in the pinned blueprint version's candidate-application tab.",
    ]],
    // An int-capable key is a JSON array, so this is `items`, not `additionalProperties`.
    '@var array<int, string>' => ['permissions', [
        'type' => 'array',
        'items' => ['type' => 'string'],
        'description' => 'Flat list of permission strings the candidate held at submit.',
        'example' => '["listing.view", "listing.create"]',
    ]],
    '@phpstan-var list<SnapshotFormData>' => ['attachments', [
        'type' => 'array',
        'items' => ['$ref' => '#/components/schemas/SnapshotFormData'],
        'description' => "Attachments carried alongside the snapshot, documented with the analyser-prefixed tag some\nteams standardise on.",
    ]],
])->group('fixture');

it('hoists the item class a recovered list names into components', function (): void {
    // The knock-on that makes reading the tag worth it: `forms` is a `list<SnapshotFormData>`, so its type
    // is the only reference to that Data class in the whole document. It hoists with its own members,
    // enum column included.
    [$schemas] = realDataComponent('App\\Data\\SnapshotData', SnapshotData::class, 'App\\Data\\SnapshotFormData');

    // The `enum` below is CASE NAMES, which is a harness artifact: this converter has no EnumSchema and
    // `App\Enums\ListingStatus` isn't autoloadable in-process, so neither route to reflection is open and
    // the DType's case names show through. The product emits the backing values (`open`/`closed`/`draft`)
    // plus x-enumDescriptions — pinned on the real engine in QueryBuilderRealEngineTest. What this
    // assertion is for is the HOIST: that the item class becomes a component of its own at all.
    expect(array_keys($schemas))->toBe(['SnapshotFormData', 'SnapshotData'])
        ->and($schemas['SnapshotFormData']['properties']['status'])->toBe([
            'type' => 'string',
            'enum' => ['Open', 'Closed', 'Draft'],
            'x-enum-varnames' => ['Open', 'Closed', 'Draft'],
            'x-enumNames' => ['Open', 'Closed', 'Draft'],
            'description' => 'Publication status frozen at submit.',
        ]);
})->group('fixture');

it('emits a referenced item for a DataCollection whose generic only the docblock states', function (): void {
    // A bare `DataCollection` is a precise reflected type that still says nothing about its elements, so
    // the constructor `@param` is read for its arguments alone.
    [$schemas, $component] = realDataComponent('App\\Data\\MfaChallengeData', MfaChallengeData::class, 'App\\Data\\SnapshotFormData');

    expect($component['properties']['mfa_factors'])->toBe([
        'type' => 'array',
        'items' => ['$ref' => '#/components/schemas/SnapshotFormData'],
        'description' => 'The factors the user can complete the challenge with.',
    ])
        ->and(array_keys($schemas))->toBe(['SnapshotFormData', 'MfaChallengeData']);
})->group('fixture');

it('carries a recovered map and list through the rule vocabulary intact', function (): void {
    // The request path routes types through validation rules, whose vocabulary has one word for every
    // array shape — so each recovered container states its own structure instead: the map as a value
    // schema, the list as the `touched_fields.*` item field Laravel writes by hand.
    $metadata = realMetadataAs('App\\Data\\SaveAnswersData', SaveAnswersData::class);
    $schema = realRequestSchema(SaveAnswersData::class, $metadata);

    // `array<string, mixed>|null` — an OBJECT with open values, not an array a JSON object fails against.
    expect($schema['properties']['answers'])->toBe(['type' => ['object', 'null'], 'additionalProperties' => []])
        ->and($schema['properties']['touched_fields'])->toBe(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($schema['properties']['zone_key'])->toBe(['type' => 'string'])
        // `touched_fields = []` has a default, so it may legitimately be omitted.
        ->and($schema['required'])->toBe(['zone_key']);
})->group('fixture');

it('leaves property inference standing when a rules() override cannot be folded', function (): void {
    // `Rule::in(MediaCollections::validNames())` names a list only the runtime knows. An `in` rule with
    // EMPTY parameters would be worth less than nothing — it wins the merge over property inference and
    // then contributes no keyword — so the descriptor folds to nothing at all and the field stays
    // unrecovered, which is what lets `#[StringType]` survive.
    $metadata = realMetadataAs('App\\Data\\UploadPolicyData', UploadPolicyData::class);
    $override = tracedOverride('app/Data/UploadPolicyData.php', 'App\\Data\\UploadPolicyData');

    expect($override->fields)->toBe([])
        ->and(realRequestSchema(UploadPolicyData::class, $metadata)['properties']['collection'])
        ->toBe(['type' => 'string'])
        ->and(realRequestSchema(UploadPolicyData::class, $metadata, $override)['properties']['collection'])
        ->toBe(['type' => 'string']);
})->group('fixture');

it('reports the unfoldable override as unrecoverable rather than dropping it silently', function (): void {
    // The other half: `collection` is absent from the traced fields, so the shared rules analysis sees it
    // as unrecoverable and diagnoses it. What was lost is the allow-list, not the field.
    $trace = FixtureRunner::traceRules('app/Data/UploadPolicyData.php', 'App\\Data\\UploadPolicyData', 'rules');

    // …which is what RulesFromClass turns into the diagnostic (RuleUnrecoverableSuppressionTest drives
    // that half in-process, where the diagnostic channel is observable).
    expect($trace['unrecoverable'])->toBe(['collection'])
        ->and($trace['fields'])->toBe([]);
})->group('fixture');

it('omits a request property for a field the API prohibits outright', function (): void {
    // `label` has no property at all — the override names it only to reject it. An unconditional
    // `prohibited` therefore drops the field from the documented body: an optional, shapeless property
    // would invite exactly what the API refuses.
    $metadata = realMetadataAs('App\\Data\\UpdateNodeData', UpdateNodeData::class);
    $override = tracedOverride('app/Data/UpdateNodeData.php', 'App\\Data\\UpdateNodeData');
    $schema = realRequestSchema(UpdateNodeData::class, $metadata, $override);

    expect(array_keys($schema['properties']))->toBe(['name', 'metadata', 'position'])
        ->and($schema)->not->toHaveKey('required');
})->group('fixture');

it('documents a positional tuple as an array, never as an object with numeric property names', function (): void {
    // `@param array{float, float} $position`, straight out of the fixture app through the real engine.
    // Synthesising `position.0`/`position.1` child paths here would drop the `array` rule (a named child
    // means an object) and emit `properties` as a JSON ARRAY — not a shape any JSON Schema has. A vague
    // `{"type": "array"}` is the honest answer for a tuple the rule vocabulary cannot describe.
    $metadata = realMetadataAs('App\\Data\\UpdateNodeData', UpdateNodeData::class);
    $schema = realRequestSchema(UpdateNodeData::class, $metadata);

    expect($schema['properties']['position'])->toBe(['type' => 'array']);
})->group('fixture');

it('resolves a dotted rule key to an object rather than an array with properties', function (): void {
    // `metadata` gets `array` from its own rule and `properties` from its dotted child. Both would be
    // `{"type": "array", "properties": …}`, which no document validates against — so the named child
    // wins and the `array` rule is dropped: a dotted key means the field is an object.
    $metadata = realMetadataAs('App\\Data\\UpdateNodeData', UpdateNodeData::class);
    $override = tracedOverride('app/Data/UpdateNodeData.php', 'App\\Data\\UpdateNodeData');
    $schema = realRequestSchema(UpdateNodeData::class, $metadata, $override);

    expect($schema['properties']['metadata'])->toBe([
        'type' => 'object',
        'properties' => [
            'retention' => [
                'type' => 'object',
                'properties' => [
                    'mode' => [
                        'type' => 'string',
                        'description' => 'Required when any of metadata is present.',
                    ],
                ],
            ],
        ],
    ]);
})->group('fixture');

it('keeps a recovered container through a rules() override that only restates `array`', function (string $property, array $expected): void {
    // The real-source half of the same story: `array` is the ONE word the rule vocabulary has for every
    // array shape, so an override stating it restates what the constructor `@param` already recovered
    // rather than replacing it — and `{"type": "array"}` for a JSON object is wrong, not merely vague.
    // `metadata` proves it survives a size-only custom rule sitting alongside; `touched_fields` is the
    // half that already worked, its item field riding on a key of its own.
    $metadata = realMetadataAs('App\\Data\\ActionPreviewData', ActionPreviewData::class);
    $override = tracedOverride('app/Data/ActionPreviewData.php', 'App\\Data\\ActionPreviewData');
    $schema = realRequestSchema(ActionPreviewData::class, $metadata, $override);

    expect($schema['properties'][$property])->toBe($expected);
})->with([
    'array<string, mixed>' => ['config', ['type' => 'object', 'additionalProperties' => []]],
    // No `nullable` in the override, so the API really does refuse an explicit null here — the property
    // being `?array` describes the hydrated value, not what the rules admit.
    'array<string, mixed>|null' => ['metadata', [
        'type' => 'object',
        'additionalProperties' => [],
        'maxLength' => 65536,
        'description' => 'At most 64 KiB once encoded.',
    ]],
    'list<string>' => ['touched_fields', ['type' => 'array', 'items' => ['type' => 'string']]],
])->group('fixture');

it('appends a rules() override to property inference under #[MergeValidationRules]', function (): void {
    // Spatie's resolver `merge`s instead of `add`ing when the class carries the attribute, so the
    // property's own `#[Max(255)]` keeps applying alongside the override's `min:3` — documenting the
    // replacement here would drop a constraint the API still enforces.
    $metadata = realMetadataAs('App\\Data\\MergedRulesData', MergedRulesData::class);
    $override = tracedOverride('app/Data/MergedRulesData.php', 'App\\Data\\MergedRulesData');
    $schema = realRequestSchema(MergedRulesData::class, $metadata, $override);

    expect($schema['properties']['name'])->toBe(['type' => 'string', 'maxLength' => 255, 'minLength' => 3, 'example' => 'example'])
        ->and($schema['required'])->toBe(['name']);
})->group('fixture');

it('documents the recovered metadata map when no rules() override replaces it', function (): void {
    // Without the override the same `@param array<string, mixed>|Optional|null $metadata` documents as
    // an object with open values — the shape the override then narrows.
    $metadata = realMetadataAs('App\\Data\\UpdateNodeData', UpdateNodeData::class);

    expect(realRequestSchema(UpdateNodeData::class, $metadata)['properties']['metadata'])
        ->toBe(['type' => ['object', 'null'], 'additionalProperties' => []]);
})->group('fixture');

it('attaches a #[Mock] hint to the real recovered shape, following a #[MapName] to its published key', function (): void {
    // The types and the prose here are the real engine's; only the hints are the attribute's. A hint
    // has to sit beside what was recovered rather than replacing any of it — and a renamed property
    // publishes its hint under the name the wire uses, not the one the PHP declares.
    [, $component] = realDataComponent('App\Data\SnapshotData', MockedSnapshotData::class, 'App\Data\SnapshotFormData');

    expect($component['properties']['snapshot_schema_version'])->toBe([
        'type' => 'integer',
        'description' => 'Snapshot schema version. Bumped if the shape changes; renderers branch on this.',
        'example' => '1',
        'x-docuccino' => ['mock' => ['faker' => 'randomDigit', 'seedGroup' => 'snapshot']],
    ])
        ->and($component['properties']['profile']['x-docuccino'])->toBe(['mock' => ['faker' => 'safeEmail']])
        ->and($component['properties'])->not->toHaveKey('candidate')
        // The class-level form reaches a member exactly as it does on a model or a FormRequest.
        ->and($component['properties']['permissions']['x-docuccino'])->toBe(['mock' => ['faker' => 'numberBetween:1,9']])
        // …and everything unnamed is byte-identical to what the untouched twin publishes.
        ->and($component['properties']['forms'])->not->toHaveKey('x-docuccino');
})->group('fixture');
