<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\FragmentCache;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Integrations\SpatieData\SpatieDataDigestContributor;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Tests\Fixtures\Rules\BankReference;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\CustomRuleController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\CustomRuleData;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\LateBoundMarker;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;

/**
 * Write a throwaway PHP file and return its path — a stand-in for a returned DTO/model/enum/resource
 * source file whose reflected shape a schema mapper records via SchemaContext::dependsOn.
 */
function tempDependencyFile(): string
{
    $file = sys_get_temp_dir().'/docuccino-schemadep-'.uniqid('', true).'.php';
    file_put_contents($file, '<?php // v1');

    return $file;
}

/**
 * The OperationFragment cache: warm hits are byte-identical and skip the engine, and fragments
 * invalidate when the document config or a dependency file changes.
 */
function enableFragmentCache(): string
{
    $dir = sys_get_temp_dir().'/docuccino-fragments-'.uniqid('', true);

    config()->set('docuccino.cache.enabled', true);
    config()->set('docuccino.cache.path', $dir);

    return $dir;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/docuccino-fragments-*') ?: [] as $dir) {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }
});

it('serves a warm cache hit byte-identically while skipping the engine', function (): void {
    enableFragmentCache();
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    // Cold run: the engine is exercised and fragments are written.
    $cold = (new UirEmitter)->emit(generateDocument()->document);
    expect($engine->analyzeCount)->toBeGreaterThan(0);

    // Warm run: every fragment is served from cache; the engine is never touched.
    $engine->analyzeCount = 0;
    $warm = (new UirEmitter)->emit(generateDocument()->document);

    expect($warm)->toBe($cold)
        ->and($engine->analyzeCount)->toBe(0);
});

/**
 * @param  array<string, mixed>  $raw
 * @return array<string, mixed>
 */
function withProblemDetails(array $raw): array
{
    $raw['error_responses'] = ['preset' => 'problem-details'];

    return $raw;
}

it('serves a warm cache hit byte-identically for a problem-details document', function (): void {
    // A warm hit rebuilds `components` from the `$ref`s each fragment carries, so anything registered
    // without being referenced exists only on a cold build — the preset's shared `Problem*` responses
    // and the throttled route's 429 are where that asymmetry shows up.
    enableFragmentCache();
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    $cold = (new UirEmitter)->emit(generateDocument(withProblemDetails(...))->document);
    expect($engine->analyzeCount)->toBeGreaterThan(0);

    $engine->analyzeCount = 0;
    $warm = (new UirEmitter)->emit(generateDocument(withProblemDetails(...))->document);

    expect($warm)->toBe($cold)
        ->and($engine->analyzeCount)->toBe(0);
});

it('invalidates fragments when the document config changes', function (): void {
    enableFragmentCache();
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    // A representation policy change alters the document configHash → every key changes → miss.
    config()->set('docuccino.documents.default.representation.operation_id', 'controller-method');
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('invalidates a fragment when one of its dependency files changes', function (): void {
    enableFragmentCache();

    $dependency = sys_get_temp_dir().'/docuccino-dep-'.uniqid('', true).'.php';
    file_put_contents($dependency, '<?php // v1');

    // One route's analysis declares the temp file as a dependency; the rest use the stub default.
    $stub = new StubTypeEngine(
        analyses: [
            'Workbench\\App\\Http\\Controllers\\FormController::index' => new ActionAnalysis(
                returns: [new ReturnSite(new ListT(new ClassT('Workbench\\App\\Data\\FormData')), new SourceLocation(''))],
                dependencyFiles: [$dependency],
            ),
        ],
    );
    $engine = new CountingTypeEngine($stub);
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    // Touch the dependency: its stored hash no longer matches → the dependent fragment invalidates.
    file_put_contents($dependency, '<?php // v2');
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);

    @unlink($dependency);
});

it('invalidates a fragment when a returned DTO file is edited (classMetadata dependency)', function (): void {
    enableFragmentCache();

    $dto = tempDependencyFile();
    // FormData's reflected shape now declares the temp file as its dependency, mirroring what the
    // real ClassMetadataFactory surfaces; DataSchema records it via SchemaContext::dependsOn.
    $metadata = new ClassMetadata('Workbench\\App\\Data\\FormData', [
        new PropertyMetadata('id', ScalarT::int()),
        new PropertyMetadata('title', ScalarT::string()),
    ], dependencyFiles: [$dto]);

    $engine = new CountingTypeEngine(WorkbenchEngine::make(classOverrides: ['Workbench\\App\\Data\\FormData' => $metadata]));
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    // Editing the DTO file changes its stored hash → the FormData-dependent fragments rebuild.
    file_put_contents($dto, '<?php // v2');
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);

    @unlink($dto);
});

it('invalidates a fragment when a returned model file is edited (classMetadata dependency)', function (): void {
    enableFragmentCache();

    $model = tempDependencyFile();
    $widget = 'Docuccino\\Laravel\\Tests\\Fixtures\\Eloquent\\Widget';
    $metadata = new ClassMetadata($widget, [
        new PropertyMetadata('id', ScalarT::int()),
        new PropertyMetadata('name', ScalarT::string()),
    ], dependencyFiles: [$model]);

    $engine = new CountingTypeEngine(WorkbenchEngine::make(classOverrides: [$widget => $metadata]));
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    file_put_contents($model, '<?php // v2');
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);

    @unlink($model);
});

it('invalidates a fragment when a resource toArray file is edited (analysis dependency propagated)', function (): void {
    enableFragmentCache();

    $resourceFile = tempDependencyFile();
    // The resource's toArray analysis declares the temp file as a dependency; ToArrayObject
    // propagates $analysis->dependencyFiles through SchemaContext::dependsOn.
    $toArray = new ActionAnalysis(
        returns: [new ReturnSite(new ArrayShapeT([
            new ArrayShapeField('id', ScalarT::int()),
            new ArrayShapeField('title', ScalarT::string()),
        ]), new SourceLocation(''))],
        dependencyFiles: [$resourceFile],
    );

    $engine = new CountingTypeEngine(WorkbenchEngine::make(analysisOverrides: [
        'Docuccino\\Laravel\\Tests\\Fixtures\\ApiResources\\ArticleResource::toArray' => $toArray,
    ]));
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    file_put_contents($resourceFile, '<?php // v2');
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);

    @unlink($resourceFile);
});

it('invalidates fragments when Relation::morphMap() changes (booted-app cache input)', function (): void {
    enableFragmentCache();
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    // A morph-map change alters the discriminator vocabulary → the document-level env digest changes.
    Relation::morphMap(['widget' => 'Docuccino\\Laravel\\Tests\\Fixtures\\Eloquent\\Widget', 'gadget' => 'Docuccino\\Laravel\\Tests\\Fixtures\\Eloquent\\Gadget', 'sprocket' => 'Docuccino\\Laravel\\Tests\\Fixtures\\Eloquent\\Widget'], false);
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);

    // Restore the morph map so this test never leaks into another (see TestCase setUp).
    Relation::morphMap(['widget' => 'Docuccino\\Laravel\\Tests\\Fixtures\\Eloquent\\Widget', 'gadget' => 'Docuccino\\Laravel\\Tests\\Fixtures\\Eloquent\\Gadget'], false);
});

it('invalidates fragments when a render callback is registered (booted-app cache input)', function (): void {
    enableFragmentCache();
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    // Adding a handler re-documents the inferred-handler error tier — an asymmetry per-file hashes miss,
    // so the registered render-callback set is folded into the env digest.
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable(static fn (RuntimeException $e) => response()->json(['error' => 'boom'], 400));
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('invalidates fragments when app.url changes (booted-app cache input)', function (): void {
    enableFragmentCache();
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    // app.url feeds Passport oauth2 flow URLs into operation security → folded into the env digest.
    config()->set('app.url', 'https://changed.example.test');
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('invalidates fragments when a RateLimiter::for registration is added (booted-app cache input)', function (): void {
    enableFragmentCache();
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    // The add-a-limiter asymmetry: a route carrying `throttle:brand-new` documents the numberless 429
    // floor and records no closure dependency, so registering the limiter afterwards has to refresh the
    // fragments through the document-level RateLimiter registration-set digest, not a route file.
    RateLimiterFacade::for('brand-new-limiter', static fn (): Limit => Limit::perMinute(10));
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('invalidates fragments when the query-builder parameter names change (booted-app cache input)', function (): void {
    enableFragmentCache();
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    // Renaming query-builder's `filter` parameter reshapes every QB operation but touches no route file,
    // so the query-builder config digest is what invalidates the warm fragments.
    config()->set('query-builder.parameters.filter', 'q');
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('gates each integration environment-digest contributor with its integration', function (): void {
    // The spatie-data digest contributor is contributed when the integration is enabled and omitted when
    // the document disables it, so a disabled integration's globals never key the cache.
    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $factory = app(DocumentConfigFactory::class);

    $enabled = DefaultExtensions::all($factory->make('default', $raw, 'skeleton'));
    $raw['integrations']['spatie_data']['enabled'] = false;
    $disabled = DefaultExtensions::all($factory->make('default', $raw, 'skeleton'));

    expect($enabled)->toContain(SpatieDataDigestContributor::class)
        ->and($disabled)->not->toContain(SpatieDataDigestContributor::class);
});

it('invalidates every fragment when the resolved extension set changes', function (): void {
    enableFragmentCache();
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    // Registering a new extension changes the resolved extension signature → every key changes.
    Docuccino::extend(new LateBoundMarker);
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('invalidates a fragment when one of its dependency files is REMOVED', function (): void {
    enableFragmentCache();

    $dependency = sys_get_temp_dir().'/docuccino-dep-'.uniqid('', true).'.php';
    file_put_contents($dependency, '<?php // v1');

    $stub = new StubTypeEngine(
        analyses: [
            'Workbench\\App\\Http\\Controllers\\FormController::index' => new ActionAnalysis(
                returns: [new ReturnSite(new ListT(new ClassT('Workbench\\App\\Data\\FormData')), new SourceLocation(''))],
                dependencyFiles: [$dependency],
            ),
        ],
    );
    $engine = new CountingTypeEngine($stub);
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    // Deleting the dependency makes its stored hash unverifiable → the dependent fragment invalidates.
    @unlink($dependency);
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('keys fragments per route so distinct routes never collide', function (): void {
    $cache = new FragmentCache(true, sys_get_temp_dir(), 't', 's', 'v');

    $forms = $cache->key('GET /api/forms', 'cfg', ['Ext@1.0']);
    $widgets = $cache->key('POST /api/widgets', 'cfg', ['Ext@1.0']);
    $formsOtherExt = $cache->key('GET /api/forms', 'cfg', ['Ext@2.0']);

    expect($forms)->not->toBe($widgets)
        ->and($forms)->not->toBe($formsOtherExt);
});

it('invalidates the query-builder fragment when an enum-cast filter file changes (feature 1 dependency)', function (): void {
    enableFragmentCache();
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    // Cold run: the QB route's filter[status] enum schema is derived from Form's WidgetStatus cast,
    // and the enum's declaring file is recorded as a fragment dependency by the QB integration.
    generateDocument()->document;
    $engine->analyzeCount = 0;

    // Warm run with the enum file untouched: the QB fragment is a byte-identical cache hit.
    generateDocument()->document;
    expect($engine->analyzeCount)->toBe(0);

    // Editing the enum's declaring file changes its stored hash → the enum-typed QB fragment rebuilds.
    $enumFile = (string) (new ReflectionEnum('Workbench\\App\\Enums\\WidgetStatus'))->getFileName();
    $original = (string) file_get_contents($enumFile);
    try {
        file_put_contents($enumFile, $original."\n// fragment-cache dependency probe\n");
        generateDocument()->document;

        expect($engine->analyzeCount)->toBeGreaterThan(0);
    } finally {
        file_put_contents($enumFile, $original);
    }
});

it('invalidates a fragment when an annotated custom rule class is edited', function (): void {
    enableFragmentCache();
    app('router')->post('api/custom-rule-payments', [CustomRuleController::class, 'store']);

    $engine = new CountingTypeEngine(WorkbenchEngine::make(classOverrides: [
        CustomRuleData::class => new ClassMetadata(CustomRuleData::class, [
            new PropertyMetadata('reference', ScalarT::string()),
        ]),
    ]));
    app()->instance(TypeEngine::class, $engine);

    // Cold run: the Data property's `#[Rule(new BankReference)]` is documented from the rule class's
    // #[RuleSchema], and the rule class file is recorded as a fragment dependency.
    generateDocument()->document;
    $engine->analyzeCount = 0;

    generateDocument()->document;
    expect($engine->analyzeCount)->toBe(0);

    $ruleFile = (string) (new ReflectionClass(BankReference::class))->getFileName();
    $original = (string) file_get_contents($ruleFile);
    try {
        file_put_contents($ruleFile, $original."\n// fragment-cache dependency probe\n");
        generateDocument()->document;

        expect($engine->analyzeCount)->toBeGreaterThan(0);
    } finally {
        file_put_contents($ruleFile, $original);
    }
});

it('is a no-op when disabled (default): the engine runs on every build', function (): void {
    // cache.enabled defaults to false; no cache directory is configured.
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $first = $engine->analyzeCount;
    expect($first)->toBeGreaterThan(0);

    $engine->analyzeCount = 0;
    generateDocument()->document;

    expect($engine->analyzeCount)->toBe($first);
});
