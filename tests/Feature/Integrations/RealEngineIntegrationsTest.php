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
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Real-engine (out-of-process) coverage for the inference-dependent half of the integrations: the
 * type recovery they lean on, exercised by the actual PHPStan/Larastan engine rather than the
 * deterministic stub. The in-process unit tests drive the mappers; these prove the other half.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('recovers a constant status from a Data calculateResponseStatus() override', function (): void {
    // Return-type inference over the override yields a literal int — the recovery half
    // DataResponseStatus reads to re-home a 201 response.
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
    // UserResource::toArray (@mixin User) → array{id, name, email, role, badge}; the last two are
    // conditional fields.
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
    // collapses to `mixed` — the field would come out required with a permissive `{}`. The stub
    // types them `TValue|MissingValue`, so the engine recovers both the value type and the
    // MissingValue marker that ToArrayObject strips to make the field optional.
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
    // plus the concrete recovered value type.
    expect($hasMissing($byKey['role']))->toBeTrue()
        ->and($literalValue($byKey['role']))->toBe(['member'])
        ->and($hasMissing($byKey['badge']))->toBeTrue()
        ->and($literalValue($byKey['badge']))->toBe(['gold']);
})->group('fixture');

it('recovers a magic-attribute Eloquent model column universe from @property docblocks via classMetadata', function (): void {
    // App\Models\Product declares no public column properties — its attributes are magic, documented
    // with class-level @property/@property-read tags (the ide-helper convention). The engine recovers
    // those tags as the model's typed column universe, through the same classMetadata path
    // ModelSchema consumes.
    $metadata = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Models\\Product'));

    $byName = [];
    foreach ($metadata->properties as $property) {
        $byName[$property->name] = $property->type;
    }

    // Every documented column recovers, including the @property-read one (`name`) — no public
    // property and no cast, so the docblock is its only possible source. Framework bookkeeping props
    // may also be present.
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

    // `string|Optional` keeps spatie's Optional marker in the union — Optional-union properties
    // survive reflection.
    $summary = $byName['summary'];
    expect($summary)->toBeInstanceOf(UnionT::class)
        ->and(array_filter(
            $summary->members,
            static fn ($m): bool => $m instanceof ClassT && str_contains($m->fqcn, 'Optional'),
        ))->not->toBeEmpty();
})->group('fixture');

it('threads the item resource type through Resource::collection() via the collection stub', function (): void {
    // The framework docblocks `collection()` as a bare AnonymousResourceCollection (no generic), so
    // the item type is lost and the mapper emits `items: []`. The JsonResourceCollection stub makes
    // it generic and returns `AnonymousResourceCollection<static>`, so the concrete item resource
    // lands in typeArgs — what JsonResourceSchema reads to type the array items.
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
    // The engine pairs each `return` with a ReturnSite: ProfileResource has two (minimal and full),
    // and the full branch's nested `meta` carries a `when(...)` field typed `string|MissingValue`.
    // This is the recovery half of multi-site + nested-conditional merging, not the mapper mechanics.
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

    // Drive the real-recovered multi-site analysis through the mapper (keyed onto a loadable fixture,
    // since the mapper's guard reflects the class) and assert the merged contract.
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
    // With the merge stub the engine types `merge([...])` as MergeValue<array{name,email}> and
    // `mergeWhen($c, [...])` as MergeValue<array{role}>|MissingValue — the recovery half of the
    // key splice, not the splice mechanics.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Resources/DashboardResource.php',
        'App\\Http\\Resources\\DashboardResource',
        'toArray',
    ));

    $shape = $analysis->returns[0]->type ?? null;
    expect($shape)->toBeInstanceOf(ArrayShapeT::class);

    // At least one field is a MergeValue (the int-keyed merge entries) — the stub threaded the
    // generic through rather than collapsing to mixed.
    $mergeValue = 'Illuminate\\Http\\Resources\\MergeValue';
    $carriesMerge = static function (DType $t) use ($mergeValue): bool {
        $members = $t instanceof UnionT ? $t->members : [$t];

        return array_filter($members, static fn (DType $m): bool => $m instanceof ClassT && is_a($m->fqcn, $mergeValue, true)) !== [];
    };
    expect(array_filter($shape->fields, static fn ($f): bool => $carriesMerge($f->type)))->not->toBeEmpty();

    // Then the splice: merged keys sit beside id, unconditional merge keys required, mergeWhen key
    // optional, and no leftover int key.
    $engine = new StubTypeEngine(analyses: [MultiShapeResource::class.'::toArray' => $analysis]);
    $components = new ComponentRegistry;
    $converter = new SchemaConverter([new JsonResourceSchema, ...DefaultTypeMappers::all()], $engine, $components);
    $converter->toSchema(new ClassT(MultiShapeResource::class));

    $object = $components->schemas()['MultiShapeResource'];
    expect(array_keys($object['properties']))->toBe(['id', 'name', 'email', 'role'])
        ->and($object['properties'])->not->toHaveKey('0')
        ->and($object['required'])->toBe(['id', 'name', 'email']);
})->group('fixture');

it('recovers the resource-collection paginating terminal + kind through the real engine', function (string $method, string $kind, string $terminal): void {
    // The static return type is AnonymousResourceCollection<UserResource> for every mode; only the
    // call-graph terminal distinguishes them, so the real PaginationTerminalVisitor has to find the
    // paginate/simplePaginate/cursorPaginate call on the Eloquent builder receiver.
    $trace = FixtureRunner::tracePaginationTerminal(
        'app/Http/Controllers/UserPageController.php',
        'App\\Http\\Controllers\\UserPageController',
        $method,
    );

    expect($trace['paginates'])->toBeTrue()
        ->and($trace['kind'])->toBe($kind)
        ->and($trace['terminal'])->toBe($terminal)
        // Nothing renamed the key, so the framework default stands.
        ->and($trace['pageName'])->toBeNull();
})->with([
    'paginate → length' => ['lengthAware', 'length', 'paginate'],
    'simplePaginate → simple' => ['simple', 'simple', 'simplePaginate'],
    'cursorPaginate → cursor' => ['cursor', 'cursor', 'cursorPaginate'],
])->group('fixture');

it('recovers a resource collection page-size key from the request through the real engine', function (): void {
    // No Query Builder in this call graph at all: the SHARED terminal detector has to follow
    // `paginate($perPage)`'s argument back through the local variable and into `ListPageSize::clamp()`,
    // so a resource collection and a QB chain of the same shape name the same key.
    $trace = FixtureRunner::tracePaginationTerminal(
        'app/Http/Controllers/RequestPagedCollectionController.php',
        'App\\Http\\Controllers\\RequestPagedCollectionController',
        'index',
    );

    expect($trace['paginates'])->toBeTrue()
        ->and($trace['terminal'])->toBe('paginate')
        ->and($trace['pageSizeKey'])->toBe('per_page')
        ->and($trace['pageSizeDefault'])->toBeNull();
})->group('fixture');

it('claims no page-size key for a terminal whose size is a call-site literal, on the real engine', function (string $method): void {
    // The negative path on the shared detector: `paginate(15)` reads nothing off the request.
    $trace = FixtureRunner::tracePaginationTerminal(
        'app/Http/Controllers/UserPageController.php',
        'App\\Http\\Controllers\\UserPageController',
        $method,
    );

    expect($trace['paginates'])->toBeTrue()
        ->and($trace['pageSizeKey'])->toBeNull();
})->with(['lengthAware', 'simple', 'cursor'])->group('fixture');

it('recovers a page key the call site renamed through the real engine', function (): void {
    // `paginate(15, ['*'], 'p')` — the fold has to reach the third argument past a `['*']` columns
    // array, or the document names a key this endpoint does not read.
    $trace = FixtureRunner::tracePaginationTerminal(
        'app/Http/Controllers/UserPageController.php',
        'App\\Http\\Controllers\\UserPageController',
        'renamedKey',
    );

    expect($trace['kind'])->toBe('length')
        ->and($trace['pageName'])->toBe('p');
})->group('fixture');

it('recognises a resource wrapping Model::create() as a 201 through the real engine', function (string $method, bool $created): void {
    // store() returns new UserResource(User::create(...)) → wasRecentlyCreated → 201; show() wraps an
    // existing model → stays 200. The recovery half, not just the AST match.
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

it('recovers a real timacdonald JSON:API resource attributes shape and maps it to the JSON:API document', function (): void {
    // The engine reflects the timacdonald resource's toAttributes() into {title, body}.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Resources/ArticleJsonApiResource.php',
        'App\\Http\\Resources\\ArticleJsonApiResource',
        'toAttributes',
    ));

    $shape = $analysis->returns[0]->type ?? null;
    expect($shape)->toBeInstanceOf(ArrayShapeT::class)
        ->and(array_map(static fn ($field): string => (string) $field->key, $shape->fields))->toBe(['title', 'body']);

    // Real recovery → real mapper: the shape goes through the timacdonald mapper and the shared
    // JSON:API document builder. The mapper's class guard reflects the resource FQCN, so the shape is
    // seeded onto a loadable test fixture instead.
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

    // The hoisted component is the bare resource object; the `{data: …}` envelope is applied at the
    // response root, so a collection can reference the object directly.
    $object = $components->schemas()['TimacdonaldArticleResource'];
    expect($object['required'])->toBe(['id', 'type'])
        ->and($object['properties']['attributes']['properties'])->toHaveKeys(['title', 'body'])
        ->and($object['properties'])->not->toHaveKey('relationships');
})->group('fixture');

it('recovers spatie jsonPaginate() through the real engine and maps it to page[number]/page[size]', function (): void {
    // The shared PaginationTerminalVisitor runs in the engine subprocess: it has to spot the
    // jsonPaginate() terminal one call deep, match the where-narrowed Eloquent builder receiver, and
    // fold the two literal overrides out of the outermost call site's int args.
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

    // Terminal + overrides become the bracketed page params, the folded literals driving the size
    // default and ceiling.
    expect(array_keys($byName))->toBe(['page[number]', 'page[size]'])
        ->and($byName['page[size]']->schema['default'])->toBe(25)
        ->and($byName['page[size]']->schema['maximum'])->toBe(100);
})->group('fixture');

it('recovers a Validator::make() rule array inside a Queries class reached by descent from the action', function (): void {
    // The modular GET-params pattern: the action calls a Queries method that runs
    // Validator::make($input, [...]) one hop away, so the engine's bounded descent has to reach that
    // call for InlineRulesVisitor to recover the literal rule array.
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
    // ShapeToRuleSet alone drops Rule::enum silently — by the DType stage the descriptor is a bare
    // object. RulesMethodVisitor traces the returned array with constant folding, so the enum
    // descriptor survives with its backing values and FQCN. The closure-ruled field is recovered by
    // neither path, so it's flagged unrecoverable rather than silently dropped.
    $trace = FixtureRunner::traceRules(
        'app/Http/Requests/StoreListingRequest.php',
        'App\\Http\\Requests\\StoreListingRequest',
        'rules',
    );

    expect(array_keys($trace['fields']))->toBe(['title', 'status', 'priority'])
        ->and($trace['unrecoverable'])->toBe(['callback']);

    $titleRules = array_map(static fn (array $r): string => $r['name'], $trace['fields']['title']);
    expect($titleRules)->toBe(['required', 'string', 'max']);

    // The descriptor folds to an `enum` rule with the backing values as parameters and the enum FQCN
    // in the note — the same shape the inline path produces.
    $statusRules = [];
    foreach ($trace['fields']['status'] as $rule) {
        $statusRules[$rule['name']] = $rule;
    }
    expect(array_keys($statusRules))->toBe(['required', 'enum'])
        ->and($statusRules['enum']['parameters'])->toBe(['open', 'closed', 'draft'])
        ->and($statusRules['enum']['note'])->toBe('App\\Enums\\ListingStatus');

    // `priority` chains `->only([ListingStatus::Open, ListingStatus::Closed])` off the descriptor: the
    // engine folds each enum-case arg to its case name, narrowing the recovered case list to those
    // two backing values.
    $priorityRules = [];
    foreach ($trace['fields']['priority'] as $rule) {
        $priorityRules[$rule['name']] = $rule;
    }
    expect(array_keys($priorityRules))->toBe(['nullable', 'enum'])
        ->and($priorityRules['enum']['parameters'])->toBe(['open', 'closed'])
        ->and($priorityRules['enum']['note'])->toBe('App\\Enums\\ListingStatus');
})->group('fixture');

it('documents a real custom rule object from its #[RuleSchema] and diagnoses the unannotated sibling', function (): void {
    // `new SortCode` is invisible to the array-shape stage and PHPStan collapses it to a bare object;
    // the fold catches the `new` at the AST level, reads the class's attribute, and maps it onto the
    // rule vocabulary. The sibling rule carries no attribute, so nothing is invented.
    $trace = FixtureRunner::traceRules(
        'app/Http/Requests/StorePaymentRequest.php',
        'App\\Http\\Requests\\StorePaymentRequest',
        'rules',
    );

    expect(array_keys($trace['fields']))->toBe(['amount', 'sort_code'])
        ->and($trace['unrecoverable'])->toBe(['signature']);

    $sortCode = [];
    foreach ($trace['fields']['sort_code'] as $rule) {
        $sortCode[$rule['name']] = $rule['parameters'];
    }

    expect($sortCode)->toBe([
        'required' => [],
        'string' => [],
        'regex' => ['/[0-9]{2}-[0-9]{2}-[0-9]{2}/'],
        'min' => ['8'],
        'max' => ['8'],
        'description' => ['A UK sort code, hyphenated.'],
        'example' => ['20-15-55'],
    ]);
})->group('fixture');

it('recovers a real laravel-actions rules() array end-to-end into a RuleSet', function (): void {
    // The engine analyses the action's literal rules() array into a constant shape…
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Actions/PublishArticleAction.php',
        'App\\Actions\\PublishArticleAction',
        'rules',
    ));

    $shape = $analysis->returns[0]->type ?? null;
    expect($shape)->toBeInstanceOf(ArrayShapeT::class);

    // …which ShapeToRuleSet (the integration's recovery tail) turns into a RuleSet.
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
    // The decorator returns jsonResponse($result) for JSON clients, so that method's return type is
    // the 200 wire shape: a `{data, meta}` envelope, distinct from handle()'s bare `{id}`, which
    // InferredResponsesExtension selects via responseAnalysisRef().
    $jsonResponse = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Actions/PublishArticleAction.php',
        'App\\Actions\\PublishArticleAction',
        'jsonResponse',
    ));
    $envelope = $jsonResponse->returns[0]->type ?? null;
    expect($envelope)->toBeInstanceOf(ArrayShapeT::class);
    $envelopeKeys = array_map(static fn ($field): string => (string) $field->key, $envelope->fields);
    expect($envelopeKeys)->toBe(['data', 'meta']);

    // handle()'s own shape is the bare `{id}` the decorator wrapped away — so the redirect really does
    // select a different, transformed wire shape.
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

// Eloquent accessor / custom-cast / $with recovery.

it('recovers a classic Eloquent accessor return type through the real engine', function (): void {
    // Product::getFullLabelAttribute(): string — the accessor's own return type is what ModelSchema
    // uses to type the `full_label` append.
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
    // The `display_name` accessor returns Attribute::make(get: function (): string { … }). What
    // matters is the get closure's type, not the method's Attribute return type — so the closure is
    // located by line, the way AccessorReader locates it.
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
    // Money::get(): float — the caster's get() return type is what ModelSchema uses to type the
    // `price` column it casts.
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
    // PublishListingData defines a static rules() (spatie's manual-rules override) mixing a pipe-string
    // rule with a Rule::enum descriptor, read through the same RulesMethodVisitor the FormRequest path
    // uses — off a static method this time.
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

    // Through DataValidationRules::build() the override wins per field over property-type inference
    // (both properties are plain `string`, which alone would infer `required|string`) — spatie's
    // DataValidationRulesResolver override semantics.
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
    // Product::seller(): BelongsTo<User, $this> — the relation return type's first type argument is the
    // related model, which ModelSchema nests under the `seller` key for a `$with` eager load.
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

/**
 * The page-size key the shared detector recovers for one action of the evidence controller, on the real
 * engine: every case below differs only in what the helper's returned value is built from.
 */
function realPageSizeKey(string $method): array
{
    $trace = FixtureRunner::tracePaginationTerminal(
        'app/Http/Controllers/PageSizeEvidenceController.php',
        'App\\Http\\Controllers\\PageSizeEvidenceController',
        $method,
    );

    expect($trace['paginates'])->toBeTrue();

    return [$trace['pageSizeKey'], $trace['pageSizeDefault']];
}

it('recovers a page-size key from a clamp the helper class imports from a trait', function (): void {
    // PHP reports a trait-imported method as the using class's own, and reflection reports the trait's
    // file — so this recovers only if the read's file and its line come from the same source.
    expect(realPageSizeKey('clampedByTrait'))->toBe(['per_page', 15]);
})->group('fixture');

it('claims no page-size key for the endpoint whose size is fixed, whatever lines the trait occupies', function (): void {
    // `summarySize()` returns 20. The trait's `per_page` read sits at a line INSIDE that method's span (the
    // assertion below is what keeps that true), so a line number compared without its file would publish
    // `per_page` for an endpoint that pages twenty at a time no matter what the client asks.
    expect(realPageSizeKey('fixedSummary'))->toBe([null, null]);
})->group('fixture');

it('has the trait read and the fixed-size method at overlapping lines, which is what the case above needs', function (): void {
    // The fixture's own premise, asserted rather than assumed: if an edit pulls these apart, the test above
    // stops proving anything and this one says so.
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;

    $traitAst = $parser->parse((string) file_get_contents(FixtureRunner::path('app/Support/Concerns/ClampsPageSize.php'))) ?? [];
    $read = $finder->findFirst(
        $traitAst,
        static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'integer',
    );

    $classAst = $parser->parse((string) file_get_contents(FixtureRunner::path('app/Support/TeamPageSize.php'))) ?? [];
    $fixed = $finder->findFirst(
        $classAst,
        static fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
            && $node->name->toString() === 'summarySize',
    );

    expect($read)->toBeInstanceOf(Node::class)
        ->and($fixed)->toBeInstanceOf(Node::class)
        ->and($read->getStartLine())->toBeGreaterThanOrEqual($fixed->getStartLine())
        ->and($read->getStartLine())->toBeLessThanOrEqual($fixed->getEndLine());
})->group('fixture');

it('recovers a page-size key named in a local inside the helper, under whatever key it reads', function (): void {
    // `limit`, not `per_page`: the recovery is evidence-driven, and nothing in it matches a name.
    expect(realPageSizeKey('limited'))->toBe(['limit', 15]);
})->group('fixture');

it('claims no page-size key for a request key the helper reads to decide something else', function (string $method): void {
    // Both helpers read the request and both answer with a literal of their own. A guard that only counted
    // the keys in the callee's lines would publish a mode selector and a sort key as page sizes.
    expect(realPageSizeKey($method))->toBe([null, null]);
})->with([
    'a match subject' => ['byPreset'],
    'an if condition' => ['recentFirst'],
])->group('fixture');

it('widens a rule whose values are spread in, rather than publishing the half it can read', function (): void {
    // `Rule::in('any', ...$this->statuses())` states four legal values and writes one of them at the rule.
    // A reader that took the written half published `enum: ["any"]`, and a client generated from that
    // REJECTS every status the endpoint accepts. `->only(Open, ...$this->alsoAllowed())` is the same
    // truncation one layer in: a half-read narrowing drops a case the API allows.
    $trace = FixtureRunner::traceRules(
        'app/Http/Requests/SpreadChoicesRequest.php',
        'App\\Http\\Requests\\SpreadChoicesRequest',
        'rules',
    );

    $names = static fn (string $field): array => array_map(
        static fn (array $rule): string => $rule['name'],
        $trace['fields'][$field],
    );

    // The control: every value is written at the rule, so every value is published.
    expect($names('visibility'))->toBe(['required', 'in'])
        ->and($trace['fields']['visibility'][1]['parameters'])->toBe(['public', 'private']);

    // No `in` at all rather than a short one, and an enum that keeps every case the half-read `only()`
    // would have dropped.
    expect($names('status'))->toBe(['required'])
        ->and($names('priority'))->toBe(['nullable', 'enum'])
        ->and($trace['fields']['priority'][1]['parameters'])->toBe(['open', 'closed', 'draft']);

    // Widened, not unrecoverable: both fields DID recover rules, minus a constraint that was there to
    // recover — which is the one degradation nothing else reports.
    expect($trace['widened'])->toBe(['status', 'priority'])
        ->and($trace['unrecoverable'])->toBe([]);
})->group('fixture');

it('leaves a soft-delete filter unresolved when its key is not written at the call', function (): void {
    // Spatie documents `AllowedFilter::trashed()` as filtering on `trashed`, which is true of a call that
    // passed NO name. This one passes one it reads from config, so the endpoint accepts some other key and
    // publishing `trashed` names a query parameter it does not have.
    $trace = FixtureRunner::traceQbEnrich(
        'app/Http/Controllers/TrashedFilterController.php',
        'App\\Http\\Controllers\\TrashedFilterController',
        'index',
    );

    expect(array_map(static fn (array $filter): string => $filter['name'], $trace['filters']))->toBe(['status'])
        ->and($trace['unresolved'])->toHaveCount(1)
        ->and($trace['unresolved'][0])->toContain('TrashedFilterController.php');
})->group('fixture');
