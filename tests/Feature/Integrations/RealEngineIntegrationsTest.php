<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Integrations\ApiResources\JsonResourceSchema;
use Docuccino\Laravel\Integrations\FormRequest\ShapeToRuleSet;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateConfig;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateFacts;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateParameters;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldJsonApiResourceSchema;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\MultiShapeResource;
use Docuccino\Laravel\Tests\Fixtures\TimacdonaldJsonApi\TimacdonaldArticleResource;

/**
 * Real-engine (out-of-process) coverage for the inference-dependent halves of the Phase-4 and Phase-5c
 * integrations, so the type-recovery those integrations lean on is exercised by the ACTUAL
 * PHPStan/Larastan engine — not only the deterministic stub. Complements the in-process unit tests
 * (which drive the mappers) and the existing JsonResponse status/payload real-engine smoke.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('recovers a constant status from a Data calculateResponseStatus() override', function (): void {
    // The real engine's return-type inference over the overridden method yields a literal-int type —
    // the recovery half DataResponseStatus reads to re-home a 201 response (gap 5).
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Data/CreatedThingData.php',
        'App\\Data\\CreatedThingData',
        'calculateResponseStatus',
    ));

    $type = $analysis->returns[0]->type ?? null;
    expect($type)->toBeInstanceOf(LiteralT::class)
        ->and($type->value)->toBe(201);
})->group('fixture');

it('recovers an API resource toArray shape as a constant array shape', function (): void {
    // The real engine analyses UserResource::toArray (@mixin User) into an
    // array{id, name, email, role, badge} — the last two are conditional fields.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Resources/UserResource.php',
        'App\\Http\\Resources\\UserResource',
        'toArray',
    ));

    $type = $analysis->returns[0]->type ?? null;
    expect($type)->toBeInstanceOf(ArrayShapeT::class);

    $keys = array_map(static fn ($field): string => (string) $field->key, $type->fields);
    expect($keys)->toBe(['id', 'name', 'email', 'role', 'badge']);
})->group('fixture');

it('types API resource conditional fields as T|MissingValue via the ConditionallyLoadsAttributes stub', function (): void {
    // Without the stub, `when(...)`/`whenLoaded(...)` return `MissingValue|mixed`, which PHPStan
    // collapses to `mixed` (audit api-resources #1) — the field would be required + permissive `{}`.
    // The stub gives them `TValue|MissingValue`, so the real engine recovers the value type AND the
    // MissingValue marker ToArrayObject strips to make the field optional.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Resources/UserResource.php',
        'App\\Http\\Resources\\UserResource',
        'toArray',
    ));

    $byKey = [];
    foreach (($analysis->returns[0]->type->fields ?? []) as $field) {
        $byKey[(string) $field->key] = $field->type;
    }

    $missing = 'Illuminate\\Http\\Resources\\MissingValue';
    $hasMissing = static fn (DType $t): bool => $t instanceof UnionT
        && array_filter($t->members, static fn (DType $m): bool => $m instanceof ClassT && $m->fqcn === $missing) !== [];
    $literalValue = static fn (DType $t): array => array_values(array_map(
        static fn (LiteralT $l) => $l->value,
        array_filter(($t instanceof UnionT ? $t->members : [$t]), static fn (DType $m): bool => $m instanceof LiteralT),
    ));

    // `role` (value form) and `badge` (whenLoaded closure form) both carry the marker (→ optional)
    // and the concrete recovered value type.
    expect($hasMissing($byKey['role']))->toBeTrue()
        ->and($literalValue($byKey['role']))->toBe(['member'])
        ->and($hasMissing($byKey['badge']))->toBeTrue()
        ->and($literalValue($byKey['badge']))->toBe(['gold']);
})->group('fixture');

it('recovers a magic-attribute Eloquent model column universe from @property docblocks via classMetadata', function (): void {
    // App\Models\Product declares NO public column properties — its attributes are magic — and
    // documents them with class-level @property/@property-read tags (the ide-helper convention).
    // The real engine recovers those tags as the model's typed column universe: the same
    // classMetadata path ModelSchema consumes, now sourced from docblocks rather than a shape no
    // real model has (Finding 0).
    $metadata = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Models\\Product'));

    $byName = [];
    foreach ($metadata->properties as $property) {
        $byName[$property->name] = $property->type;
    }

    // Every documented column recovers, including the @property-read one (`name`) — which has no
    // public property AND no cast, so its only possible source is the docblock. Framework
    // bookkeeping props may also be present.
    expect($byName)->toHaveKeys(['id', 'sku', 'description', 'name']);

    // Precise types from the docblock grammar: id is an int, the ?string column is a string|null
    // union, and a @property-read column is a plain string.
    expect($byName['id']->canonicalKey())->toBe(ScalarT::int()->canonicalKey())
        ->and($byName['name']->canonicalKey())->toBe(ScalarT::string()->canonicalKey())
        ->and($byName['description'])->toBeInstanceOf(UnionT::class)
        ->and(array_filter($byName['description']->members, static fn ($m): bool => $m instanceof NullT))->not->toBeEmpty();
})->group('fixture');

it('recovers a real Data class shape via classMetadata (property types, not a stub)', function (): void {
    // The real engine reflects App\Data\ArticleData's typed public properties.
    $metadata = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Data\\ArticleData'));

    $byName = [];
    foreach ($metadata->properties as $property) {
        $byName[$property->name] = $property->type;
    }

    expect(array_keys($byName))->toBe(['id', 'title', 'subtitle', 'summary']);

    // Precise types recovered by reflection: id is an integer, subtitle is nullable.
    expect($byName['id']->canonicalKey())->toBe(ScalarT::int()->canonicalKey());

    $subtitle = $byName['subtitle'];
    expect($subtitle)->toBeInstanceOf(UnionT::class)
        ->and(array_filter($subtitle->members, static fn ($m): bool => $m instanceof NullT))->not->toBeEmpty();

    // The `string|Optional` union is recovered as a union whose members include spatie's Optional
    // marker class — the real-engine proof that Optional-union properties survive reflection.
    $summary = $byName['summary'];
    expect($summary)->toBeInstanceOf(UnionT::class)
        ->and(array_filter(
            $summary->members,
            static fn ($m): bool => $m instanceof ClassT && str_contains($m->fqcn, 'Optional'),
        ))->not->toBeEmpty();
})->group('fixture');

it('threads the item resource type through Resource::collection() via the collection stub', function (): void {
    // Framework docblocks `collection()` as a bare AnonymousResourceCollection (no generic), so the
    // item type was lost and the mapper emitted `items: []` (audit api-resources #2). The
    // JsonResourceCollection stub makes AnonymousResourceCollection generic and returns
    // `AnonymousResourceCollection<static>`, so `UserResource::collection(User::all())` recovers the
    // concrete item resource in typeArgs — what JsonResourceSchema reads to type the array items.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/SpikeController.php',
        'App\\Http\\Controllers\\SpikeController',
        'resourceCollection',
    ));

    $type = $analysis->returns[0]->type ?? null;
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn)->toBe('Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection')
        ->and($type->typeArgs[0] ?? null)->toBeInstanceOf(ClassT::class)
        ->and($type->typeArgs[0]->fqcn)->toBe('App\\Http\\Resources\\UserResource');
})->group('fixture');

it('types $model->toResource(Class) and $collection->toResourceCollection(Class) via the stubs', function (): void {
    // toResource(UserResource::class) recovers the named resource (not a bare JsonResource).
    $resource = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/SpikeController.php',
        'App\\Http\\Controllers\\SpikeController',
        'modelToResource',
    ));
    $resourceType = $resource->returns[0]->type ?? null;
    expect($resourceType)->toBeInstanceOf(ClassT::class)
        ->and($resourceType->fqcn)->toBe('App\\Http\\Resources\\UserResource');

    // toResourceCollection(UserResource::class) recovers AnonymousResourceCollection<UserResource>.
    $collection = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/SpikeController.php',
        'App\\Http\\Controllers\\SpikeController',
        'collectionToResourceCollection',
    ));
    $collectionType = $collection->returns[0]->type ?? null;
    expect($collectionType)->toBeInstanceOf(ClassT::class)
        ->and($collectionType->fqcn)->toBe('Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection')
        ->and($collectionType->typeArgs[0] ?? null)->toBeInstanceOf(ClassT::class)
        ->and($collectionType->typeArgs[0]->fqcn)->toBe('App\\Http\\Resources\\UserResource');
})->group('fixture');

it('merges multiple toArray return sites and recurses nested conditionals through the real engine', function (): void {
    // The real engine pairs each `return` with a ReturnSite: ProfileResource has two (the minimal
    // branch and the full branch), and the full branch's nested `meta` carries a `when(...)` field
    // typed `string|MissingValue`. Proves the recovery half of Wave C items 6 (multi-site) + 7
    // (nested conditional) — not just the mapper mechanics.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Resources/ProfileResource.php',
        'App\\Http\\Resources\\ProfileResource',
        'toArray',
    ));

    $shapes = array_values(array_filter(
        array_map(static fn ($return): DType => $return->type, $analysis->returns),
        static fn (DType $t): bool => $t instanceof ArrayShapeT && ! $t->isList,
    ));
    expect($shapes)->toHaveCount(2);

    $keySets = array_map(
        static fn (ArrayShapeT $s): array => array_map(static fn ($f): string => (string) $f->key, $s->fields),
        $shapes,
    );
    expect($keySets)->toContain(['id', 'name'])
        ->and($keySets)->toContain(['id', 'email', 'meta']);

    // Drive the REAL-recovered multi-site analysis through the mapper (keyed onto a loadable fixture,
    // same technique as the timacdonald composition proof) and assert the merged contract.
    $engine = new StubTypeEngine(analyses: [MultiShapeResource::class.'::toArray' => $analysis]);
    $components = new ComponentRegistry;
    $converter = new SchemaConverter([new JsonResourceSchema, ...DefaultTypeMappers::all()], $engine, $components);
    $converter->toSchema(new ClassT(MultiShapeResource::class));

    $object = $components->schemas()['MultiShapeResource'];
    // Union of keys; only `id` (in both sites, non-optional) is required.
    expect(array_keys($object['properties']))->toBe(['id', 'name', 'email', 'meta'])
        ->and($object['required'])->toBe(['id']);
    // The nested conditional recurses: meta.role is optional, meta.name required.
    expect($object['properties']['meta']['type'])->toBe('object')
        ->and($object['properties']['meta']['required'])->toBe(['name'])
        ->and($object['properties']['meta']['properties'])->toHaveKey('role');
})->group('fixture');

it('recovers merge()/mergeWhen() as MergeValue<array{…}> and splices the keys through the real engine', function (): void {
    // With the merge stub the real engine types `merge([...])` as MergeValue<array{name,email}> and
    // `mergeWhen($c, [...])` as MergeValue<array{role}>|MissingValue — the recovery half of Wave C
    // item 5 (not just the splice mechanics).
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Resources/DashboardResource.php',
        'App\\Http\\Resources\\DashboardResource',
        'toArray',
    ));

    $shape = $analysis->returns[0]->type ?? null;
    expect($shape)->toBeInstanceOf(ArrayShapeT::class);

    // At least one field is a MergeValue (the int-keyed merge entries), proving the stub threaded the
    // generic through the engine rather than collapsing to mixed.
    $mergeValue = 'Illuminate\\Http\\Resources\\MergeValue';
    $carriesMerge = static function (DType $t) use ($mergeValue): bool {
        $members = $t instanceof UnionT ? $t->members : [$t];

        return array_filter($members, static fn (DType $m): bool => $m instanceof ClassT && is_a($m->fqcn, $mergeValue, true)) !== [];
    };
    expect(array_filter($shape->fields, static fn ($f): bool => $carriesMerge($f->type)))->not->toBeEmpty();

    // Drive the REAL-recovered shape through the mapper (keyed onto a loadable fixture) and assert the
    // splice: merged keys sit beside id, unconditional merge keys required, mergeWhen key optional.
    $engine = new StubTypeEngine(analyses: [MultiShapeResource::class.'::toArray' => $analysis]);
    $components = new ComponentRegistry;
    $converter = new SchemaConverter([new JsonResourceSchema, ...DefaultTypeMappers::all()], $engine, $components);
    $converter->toSchema(new ClassT(MultiShapeResource::class));

    $object = $components->schemas()['MultiShapeResource'];
    expect(array_keys($object['properties']))->toBe(['id', 'name', 'email', 'role'])
        ->and($object['properties'])->not->toHaveKey('0')
        ->and($object['required'])->toBe(['id', 'name', 'email']);
})->group('fixture');

it('recovers the resource-collection paginating terminal + kind through the real engine', function (string $method, string $kind): void {
    // The static return type is AnonymousResourceCollection<UserResource> for every mode; only the
    // call-graph terminal distinguishes them. The REAL PaginationTerminalVisitor must find the
    // paginate/simplePaginate/cursorPaginate terminal on the Eloquent builder receiver (Wave C item 1).
    $trace = FixtureRunner::tracePaginationTerminal(
        'app/Http/Controllers/UserPageController.php',
        'App\\Http\\Controllers\\UserPageController',
        $method,
    );

    expect($trace['paginates'])->toBeTrue()
        ->and($trace['kind'])->toBe($kind);
})->with([
    'paginate → length' => ['lengthAware', 'length'],
    'simplePaginate → simple' => ['simple', 'simple'],
    'cursorPaginate → cursor' => ['cursor', 'cursor'],
])->group('fixture');

it('recognises a resource wrapping Model::create() as a 201 through the real engine', function (string $method, bool $created): void {
    // store() returns new UserResource(User::create(...)) → wasRecentlyCreated → 201; show() wraps an
    // existing model → stays 200. Proves the recovery half of Wave C item 4 (not just the AST match).
    $trace = FixtureRunner::traceCreatedResource(
        'app/Http/Controllers/UserWriteController.php',
        'App\\Http\\Controllers\\UserWriteController',
        $method,
    );

    expect($trace['created'])->toBe($created);
})->with([
    'store wraps create() → 201' => ['store', true],
    'show wraps an existing model → 200' => ['show', false],
])->group('fixture');

// ---------------------------------------------------------------------------------------------------
// Phase 5c integrations — the recovery half proven against the REAL engine (M2 / binding coverage).
// ---------------------------------------------------------------------------------------------------

it('recovers a real timacdonald JSON:API resource attributes shape and maps it to the JSON:API document', function (): void {
    // Real recovery: the engine reflects the timacdonald resource's toAttributes() into {title, body}.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Resources/ArticleJsonApiResource.php',
        'App\\Http\\Resources\\ArticleJsonApiResource',
        'toAttributes',
    ));

    $shape = $analysis->returns[0]->type ?? null;
    expect($shape)->toBeInstanceOf(ArrayShapeT::class)
        ->and(array_map(static fn ($field): string => (string) $field->key, $shape->fields))->toBe(['title', 'body']);

    // Drive the REAL-recovered attributes shape through the timacdonald mapper + shared JSON:API
    // document builder (real recovery → real mapper), proving they compose end-to-end. The mapper's
    // class guard reflects the resource FQCN, so the composition half runs against the loadable
    // test-fixture timacdonald resource seeded with the shape the real engine just recovered.
    $engine = new StubTypeEngine(analyses: [
        TimacdonaldArticleResource::class.'::toAttributes' => $analysis,
    ]);
    $components = new ComponentRegistry;
    $converter = new SchemaConverter(
        [new TimacdonaldJsonApiResourceSchema, new JsonResourceSchema, ...DefaultTypeMappers::all()],
        $engine,
        $components,
        new RepresentationPolicy,
    );
    $converter->toSchema(new ClassT(TimacdonaldArticleResource::class));

    // The hoisted component is the resource object itself (the `{data: …}` envelope is applied at the
    // response root, not baked into the component — so a collection references the bare object).
    $object = $components->schemas()['TimacdonaldArticleResource'];
    expect($object['required'])->toBe(['id', 'type'])
        ->and($object['properties']['attributes']['properties'])->toHaveKeys(['title', 'body'])
        ->and($object['properties'])->not->toHaveKey('relationships');
})->group('fixture');

it('recovers spatie jsonPaginate() through the real engine and maps it to page[number]/page[size]', function (): void {
    // The REAL shared PaginationTerminalVisitor runs in the engine subprocess: it must recognise the
    // jsonPaginate() terminal one call deep, match the (where-narrowed) Eloquent builder receiver, and
    // fold the two literal overrides from the outermost call site's int args.
    $trace = FixtureRunner::traceJsonApiPaginate(
        'app/Http/Controllers/JsonApiPaginateController.php',
        'App\\Http\\Controllers\\JsonApiPaginateController',
        'index',
    );

    expect($trace['paginates'])->toBeTrue()
        ->and($trace['maxResults'])->toBe(100)
        ->and($trace['defaultSize'])->toBe(25);

    $facts = new JsonApiPaginateFacts;
    $facts->paginates = $trace['paginates'] === true;
    $facts->maxResultsOverride = is_int($trace['maxResults']) ? $trace['maxResults'] : null;
    $facts->defaultSizeOverride = is_int($trace['defaultSize']) ? $trace['defaultSize'] : null;

    $specs = (new JsonApiPaginateParameters)->build(new JsonApiPaginateConfig, $facts);
    $byName = [];
    foreach ($specs as $spec) {
        $byName[$spec->name] = $spec;
    }

    // The recovered terminal + overrides become the bracketed page params, with the folded literals
    // driving the size default (defaultSize) and ceiling (maxResults).
    expect(array_keys($byName))->toBe(['page[number]', 'page[size]'])
        ->and($byName['page[size]']->schema['default'])->toBe(25)
        ->and($byName['page[size]']->schema['maximum'])->toBe(100);
})->group('fixture');

it('recovers a Validator::make() rule array inside a Queries class reached by descent from the action', function (): void {
    // The modular GET-params pattern: the controller action calls a Queries method that runs
    // Validator::make($input, [...]) one hop away. The engine's bounded descent must reach that call
    // and the InlineRulesVisitor recover its literal rule array — previously promised but unproven.
    $trace = FixtureRunner::traceInlineRules(
        'app/Http/Controllers/ValidatedListController.php',
        'App\\Http\\Controllers\\ValidatedListController',
        'index',
    );

    expect(array_keys($trace['fields']))->toBe(['status', 'per_page']);

    $statusRules = array_map(static fn (array $r): string => $r['name'], $trace['fields']['status']);
    expect($statusRules)->toBe(['required', 'string']);

    $perPageRules = array_map(static fn (array $r): string => $r['name'], $trace['fields']['per_page']);
    expect($perPageRules)->toBe(['nullable', 'integer']);
})->group('fixture');

it('recovers Rule::enum(...) inside a real FormRequest rules() and diagnoses an unrecoverable field', function (): void {
    // ShapeToRuleSet alone drops Rule::enum silently (the descriptor is a bare object by the DType
    // stage, validation §1). The RulesMethodVisitor traces the returned array with constant folding,
    // so the enum descriptor survives with its backing values + FQCN; the closure-ruled field is
    // recovered by neither path and is flagged unrecoverable (diagnostic, never a silent drop).
    $trace = FixtureRunner::traceRules(
        'app/Http/Requests/StoreListingRequest.php',
        'App\\Http\\Requests\\StoreListingRequest',
        'rules',
    );

    expect(array_keys($trace['fields']))->toBe(['title', 'status', 'priority'])
        ->and($trace['unrecoverable'])->toBe(['callback']);

    $titleRules = array_map(static fn (array $r): string => $r['name'], $trace['fields']['title']);
    expect($titleRules)->toBe(['required', 'string', 'max']);

    // The enum descriptor folded to an `enum` rule with the backing values as parameters and the
    // enum FQCN in the note — the same shape the inline path produces.
    $statusRules = [];
    foreach ($trace['fields']['status'] as $rule) {
        $statusRules[$rule['name']] = $rule;
    }
    expect(array_keys($statusRules))->toBe(['required', 'enum'])
        ->and($statusRules['enum']['parameters'])->toBe(['open', 'closed', 'draft'])
        ->and($statusRules['enum']['note'])->toBe('App\\Enums\\ListingStatus');

    // `priority` chains `->only([ListingStatus::Open, ListingStatus::Closed])` off the descriptor:
    // the real engine folds each enum-case arg to its case name, so the recovered case list is
    // NARROWED to those two backing values (validation §4 #10 — chained-call folding).
    $priorityRules = [];
    foreach ($trace['fields']['priority'] as $rule) {
        $priorityRules[$rule['name']] = $rule;
    }
    expect(array_keys($priorityRules))->toBe(['nullable', 'enum'])
        ->and($priorityRules['enum']['parameters'])->toBe(['open', 'closed'])
        ->and($priorityRules['enum']['note'])->toBe('App\\Enums\\ListingStatus');
})->group('fixture');

it('recovers a real laravel-actions rules() array end-to-end into a RuleSet', function (): void {
    // Real recovery: the engine analyses the action's literal rules() array into a constant shape...
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Actions/PublishArticleAction.php',
        'App\\Actions\\PublishArticleAction',
        'rules',
    ));

    $shape = $analysis->returns[0]->type ?? null;
    expect($shape)->toBeInstanceOf(ArrayShapeT::class);

    // ...which ShapeToRuleSet (the integration's recovery tail) turns into a RuleSet.
    $ruleSet = (new ShapeToRuleSet)->convert($shape);
    expect(array_keys($ruleSet->fields))->toBe(['title', 'body']);

    $ruleNames = static fn (string $field): array => array_map(
        static fn ($rule): string => $rule->name,
        $ruleSet->fields[$field],
    );
    expect($ruleNames('title'))->toBe(['required', 'string', 'max'])
        ->and($ruleNames('body'))->toBe(['required', 'string']);
})->group('fixture');

it('recovers a laravel-actions jsonResponse() envelope distinct from handle() through the real engine', function (): void {
    // The decorator returns jsonResponse($result) for JSON clients, so ITS return type is the 200 wire
    // shape. The engine analyses jsonResponse() into the `{data, meta}` envelope — distinct from
    // handle()'s bare `{id}` — which InferredResponsesExtension selects via responseAnalysisRef().
    $jsonResponse = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Actions/PublishArticleAction.php',
        'App\\Actions\\PublishArticleAction',
        'jsonResponse',
    ));
    $envelope = $jsonResponse->returns[0]->type ?? null;
    expect($envelope)->toBeInstanceOf(ArrayShapeT::class);
    $envelopeKeys = array_map(static fn ($field): string => (string) $field->key, $envelope->fields);
    expect($envelopeKeys)->toBe(['data', 'meta']);

    // handle()'s own shape is the bare `{id}` the decorator has already wrapped away — proving the
    // redirect selects a genuinely different (transformed) wire shape, not the same one.
    $handle = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Actions/PublishArticleAction.php',
        'App\\Actions\\PublishArticleAction',
        'handle',
    ));
    $handleShape = $handle->returns[0]->type ?? null;
    expect($handleShape)->toBeInstanceOf(ArrayShapeT::class);
    $handleKeys = array_map(static fn ($field): string => (string) $field->key, $handleShape->fields);
    expect($handleKeys)->toBe(['id'])
        ->and($envelopeKeys)->not->toBe($handleKeys);
})->group('fixture');

// ---------------------------------------------------------------------------------------------------
// Wave D — Eloquent accessor / custom-cast / $with recovery, proven against the REAL engine.
// ---------------------------------------------------------------------------------------------------

it('recovers a classic Eloquent accessor return type through the real engine', function (): void {
    // App\Models\Product::getFullLabelAttribute(): string — the engine recovers the accessor's own
    // return type, which ModelSchema uses to type the `full_label` append (Wave D item 7, classic).
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Models/Product.php',
        'App\\Models\\Product',
        'getFullLabelAttribute',
    ));

    $type = $analysis->returns[0]->type ?? null;
    expect($type)->not->toBeNull()
        ->and($type->canonicalKey())->toBe(ScalarT::string()->canonicalKey());
})->group('fixture');

it('recovers an Attribute::make(get:) closure return type through the real engine', function (): void {
    // The `display_name` accessor returns Attribute::make(get: function (): string { … }); the engine
    // analyses the GET CLOSURE (located by line, as AccessorReader locates it), not the method's
    // Attribute return type, recovering `string` (Wave D item 7, Attribute form).
    $file = FixtureRunner::path('app/Models/Product.php');
    $line = 0;
    foreach (file($file) ?: [] as $index => $text) {
        if (str_contains($text, 'get: function')) {
            $line = $index + 1;
            break;
        }
    }
    expect($line)->toBeGreaterThan(0);

    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Models/Product.php',
        '',
        '',
        $line,
    ));

    $type = $analysis->returns[0]->type ?? null;
    expect($type)->not->toBeNull()
        ->and($type->canonicalKey())->toBe(ScalarT::string()->canonicalKey());
})->group('fixture');

it('recovers a custom CastsAttributes caster get() return type through the real engine', function (): void {
    // App\Casts\Money::get(): float — the engine recovers the caster's get() return type, which
    // ModelSchema uses to type the `price` column cast by it (Wave D item 7, custom cast / eloquent #9).
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Casts/Money.php',
        'App\\Casts\\Money',
        'get',
    ));

    $type = $analysis->returns[0]->type ?? null;
    expect($type)->not->toBeNull()
        ->and($type->canonicalKey())->toBe(ScalarT::float()->canonicalKey());
})->group('fixture');

it('recovers a spatie Data static rules() override through the real engine and merges it over inference', function (): void {
    // App\Data\PublishListingData defines a STATIC rules() (spatie's override, docs: validation/manual-
    // rules) mixing a pipe-string rule with a Rule::enum descriptor — read through the SAME literal +
    // descriptor engine analysis the FormRequest path uses (RulesMethodVisitor), off a static method.
    $trace = FixtureRunner::traceRules(
        'app/Data/PublishListingData.php',
        'App\\Data\\PublishListingData',
        'rules',
    );

    expect(array_keys($trace['fields']))->toBe(['title', 'status'])
        ->and($trace['unrecoverable'])->toBe([]);

    $titleRules = array_map(static fn (array $r): string => $r['name'], $trace['fields']['title']);
    expect($titleRules)->toBe(['required', 'string', 'max']);

    $statusByName = [];
    foreach ($trace['fields']['status'] as $rule) {
        $statusByName[$rule['name']] = $rule;
    }
    expect(array_keys($statusByName))->toBe(['required', 'enum'])
        ->and($statusByName['enum']['parameters'])->toBe(['open', 'closed', 'draft'])
        ->and($statusByName['enum']['note'])->toBe('App\\Enums\\ListingStatus');

    // Drive the REAL-recovered override through DataValidationRules::build(): it WINS per field over the
    // property-type inference (both properties are plain `string`, which alone would infer required|
    // string) — spatie's DataValidationRulesResolver `add` (override) semantics.
    $override = new RuleSet(array_map(
        static fn (array $rules): array => array_map(
            static fn (array $r): ValidationRule => new ValidationRule($r['name'], $r['parameters'], $r['note'] ?? null),
            $rules,
        ),
        $trace['fields'],
    ));

    $metadata = new ClassMetadata('App\\Data\\PublishListingData', [
        new PropertyMetadata('title', ScalarT::string()),
        new PropertyMetadata('status', ScalarT::string()),
    ]);
    $ruleSet = (new DataValidationRules)->build('App\\Data\\PublishListingData', $metadata, new NullTypeEngine, $override);

    // status now carries the override's enum descriptor (the bare `string` inference is replaced).
    expect(array_map(static fn (ValidationRule $r): string => $r->name, $ruleSet->fields['status']))->toBe(['required', 'enum'])
        ->and(array_map(static fn (ValidationRule $r): string => $r->name, $ruleSet->fields['title']))->toBe(['required', 'string', 'max']);
})->group('fixture');

it('resolves a $with relation\'s related model through the real engine', function (): void {
    // App\Models\Product::seller(): BelongsTo<User, $this> — the engine resolves the relation return
    // type, whose first type argument is the related model ModelSchema nests under the `seller` key
    // (Wave D item 8, $with default eager load / eloquent #13).
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Models/Product.php',
        'App\\Models\\Product',
        'seller',
    ));

    $type = $analysis->returns[0]->type ?? null;
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and(is_a($type->fqcn, 'Illuminate\\Database\\Eloquent\\Relations\\BelongsTo', true))->toBeTrue()
        ->and($type->typeArgs[0] ?? null)->toBeInstanceOf(ClassT::class)
        ->and($type->typeArgs[0]->fqcn)->toBe('App\\Models\\User');
})->group('fixture');
