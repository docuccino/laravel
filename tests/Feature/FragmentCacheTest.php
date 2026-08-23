<?php

declare(strict_types=1);

use Docuccino\Core\Document\Operation;
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
use Docuccino\Core\Pipeline\OperationFragment;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Integrations\Sanctum\SanctumDigestContributor;
use Docuccino\Laravel\Integrations\SpatieData\SpatieDataDigestContributor;
use Docuccino\Laravel\Integrations\Support\AuthConfigDigestContributor;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Tests\Fixtures\Attributes\InheritingController;
use Docuccino\Laravel\Tests\Fixtures\Attributes\LegacyBaseController;
use Docuccino\Laravel\Tests\Fixtures\Rules\BankReference;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\CustomRuleController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\CustomRuleData;
use Docuccino\Laravel\Tests\Support\ConfiguredMarker;
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
afterEach(function (): void {
    removeFragmentCacheDirs('fragments');
});

it('serves a warm cache hit byte-identically while skipping the engine', function (): void {
    fragmentCacheDir('fragments');
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
    fragmentCacheDir('fragments');
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
    fragmentCacheDir('fragments');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    // A representation policy change alters the document configHash → every key changes → miss.
    config()->set('docuccino.documents.default.representation.operation_id', 'controller-method');
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('keeps fragments warm when only the export destination changes', function (): void {
    fragmentCacheDir('fragments');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    $cold = (new UirEmitter)->emit(generateDocument()->document);
    $engine->analyzeCount = 0;

    // `export` says where artifacts land, never what they hold. Re-pointing it must not re-fingerprint
    // the document: a filename should not cost a full re-analysis, nor move a single emitted byte.
    config()->set('docuccino.documents.default.export.path', 'build/somewhere-else.json');
    $warm = (new UirEmitter)->emit(generateDocument()->document);

    expect($warm)->toBe($cold)
        ->and($engine->analyzeCount)->toBe(0);
});

it('invalidates a fragment when one of its dependency files changes', function (): void {
    fragmentCacheDir('fragments');

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
    fragmentCacheDir('fragments');

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
    fragmentCacheDir('fragments');

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
    fragmentCacheDir('fragments');

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
    fragmentCacheDir('fragments');
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
    fragmentCacheDir('fragments');
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
    fragmentCacheDir('fragments');
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
    fragmentCacheDir('fragments');
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
    fragmentCacheDir('fragments');
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

it('invalidates fragments when a query-builder include suffix changes (booted-app cache input)', function (): void {
    fragmentCacheDir('fragments');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    // The suffix reshapes every documented include enum while touching no route file.
    config()->set('query-builder.suffixes.count', 'Cnt');
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('invalidates fragments when the query-builder delimiter changes (booted-app cache input)', function (): void {
    fragmentCacheDir('fragments');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    // The delimiter decides whether lists carry the comma-array contract at all.
    config()->set('query-builder.delimiter', '|');
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('invalidates fragments when the auth guard map changes (booted-app cache input)', function (string $key, mixed $value): void {
    // Which security integration owns a route is decided by the guard's DRIVER, so re-pointing a guard
    // re-documents every operation behind it while touching no route file. Keyed by Sanctum's
    // contributor alone — which registers only when Sanctum is installed — a Passport-only app would
    // have nobody keying it, and its own contributor reads neither key.
    fragmentCacheDir('fragments');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    config()->set($key, $value);
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);
})->with([
    'a guard re-pointed at another driver' => ['auth.guards', ['api' => ['driver' => 'passport', 'provider' => 'users']]],
    'the default guard' => ['auth.defaults.guard', 'api'],
]);

it('keys auth config whether or not an auth package is installed', function (): void {
    // The gap the row above closes is a gating one: the contributor that reads auth config has to be in
    // the set for a document with every integration turned off, or an app running only one of them is
    // covered by accident.
    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    foreach (['sanctum', 'passport'] as $integration) {
        $raw['integrations'][$integration]['enabled'] = false;
    }

    $extensions = DefaultExtensions::all(app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton'));

    expect($extensions)->toContain(AuthConfigDigestContributor::class)
        ->and($extensions)->not->toContain(SanctumDigestContributor::class);
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
    fragmentCacheDir('fragments');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument()->document;
    $engine->analyzeCount = 0;

    // Registering a new extension changes the resolved extension signature → every key changes.
    Docuccino::extend(new LateBoundMarker);
    generateDocument()->document;

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('invalidates every fragment when one extension INSTANCE is reconfigured', function (): void {
    // An extension is registrable as an object on every surface there is, so its configuration lives on
    // the instance. Keyed by class name alone, `new MyExtension(mode: 'a')` and `mode: 'b'` are one
    // extension set, and the warm cache answers the second with output built under the first.
    fragmentCacheDir('fragments');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    $build = static function (string $title) use ($engine): void {
        /** @var array<string, mixed> $raw */
        $raw = config('docuccino.documents.default');
        $config = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');
        app(DocumentGenerator::class)->generate($config, $engine, [new ConfiguredMarker($title)]);
    };

    $build('A');
    $engine->analyzeCount = 0;
    $build('B');

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('invalidates a fragment when one of its dependency files is REMOVED', function (): void {
    fragmentCacheDir('fragments');

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

it('round-trips a fragment through the store, notes and all', function (): void {
    $dir = fragmentCacheDir('fragments');
    $cache = new FragmentCache(true, $dir, 't', 's', 'v');

    $fragment = new OperationFragment(
        path: '/api/forms',
        method: 'get',
        operation: Operation::fromArray([]),
        routeSignature: 'GET /api/forms',
        notes: ['deferral' => ['App\\Renderer::__invoke' => ['NotFound']]],
    );
    $cache->put('k', $fragment, []);

    expect($cache->get('k')?->notes)->toBe($fragment->notes);
});

it('misses an entry written in an older fragment format rather than reading it as “nothing to replay”', function (): void {
    // A fragment gains members over time — its notes did. An entry written before one existed has no way
    // to say "this route had none" as against "this format could not carry them", so it must not be read
    // at all: a miss costs one rebuild, while a warm build quietly reporting less than a cold one is the
    // degradation the whole equality rule exists to prevent.
    $dir = fragmentCacheDir('fragments');
    $cache = new FragmentCache(true, $dir, 't', 's', 'v');

    $fragment = new OperationFragment('/api/forms', 'get', Operation::fromArray([]), 'GET /api/forms');
    $cache->put('legacy', $fragment, []);

    $file = $dir.'/legacy.json';
    /** @var array<string, mixed> $entry */
    $entry = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

    expect($cache->get('legacy'))->not->toBeNull()
        ->and($entry['format'])->toBe(FragmentCache::FORMAT);

    // An entry from before the stamp existed, and one from a format this build doesn't read.
    $unstamped = $entry;
    unset($unstamped['format']);
    file_put_contents($file, json_encode($unstamped, JSON_THROW_ON_ERROR));
    expect($cache->get('legacy'))->toBeNull();

    file_put_contents($file, json_encode([...$entry, 'format' => FragmentCache::FORMAT - 1], JSON_THROW_ON_ERROR));
    expect($cache->get('legacy'))->toBeNull();
});

it('invalidates the query-builder fragment when an enum-cast filter file changes (feature 1 dependency)', function (): void {
    $dir = fragmentCacheDir('fragments');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    // Cold run: the QB route's filter[status] enum schema is derived from Form's WidgetStatus cast,
    // and the enum's declaring file is recorded as a fragment dependency by the QB integration.
    generateDocument()->document;
    $engine->analyzeCount = 0;

    // Warm run with the enum file untouched: the QB fragment is a byte-identical cache hit.
    generateDocument()->document;
    expect($engine->analyzeCount)->toBe(0);

    // The enum's declaring file is recorded as a dependency of the QB fragment, and a fragment whose
    // recorded hash no longer matches the file rebuilds.
    //
    // The disagreement is staged in the cache rather than by editing the enum on disk: the workbench
    // is shared by every parallel test process, so a file rewritten here — even restored afterwards —
    // invalidates whatever another process happens to be hashing at that moment. That made this row
    // fail a peer's warm-cache assertion at random. Rewriting the stored hash proves the same two
    // things and touches nothing outside this row's own cache directory.
    $enumFile = (string) (new ReflectionEnum('Workbench\\App\\Enums\\WidgetStatus'))->getFileName();
    $rewritten = 0;

    foreach (glob($dir.'/*.json') ?: [] as $entry) {
        /** @var array{dependencies?: list<array{file?: string, hash?: string}>} $decoded */
        $decoded = json_decode((string) file_get_contents($entry), true);
        $changed = false;

        foreach ($decoded['dependencies'] ?? [] as $index => $dependency) {
            if (($dependency['file'] ?? null) === $enumFile) {
                $decoded['dependencies'][$index]['hash'] = str_repeat('0', 64);
                $changed = true;
            }
        }

        if ($changed) {
            file_put_contents($entry, (string) json_encode($decoded));
            $rewritten++;
        }
    }

    // Half the claim: the enum file really is on some fragment's dependency list.
    expect($rewritten)->toBeGreaterThan(0);

    generateDocument()->document;

    // The other half: a dependency that no longer hashes to what was stored rebuilds the fragment.
    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('invalidates a fragment when the PARENT controller carrying the class attributes changes', function (): void {
    $dir = fragmentCacheDir('fragments');
    app('router')->get('api/zz-attr-inherit', [InheritingController::class, 'index']);

    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    // Cold run: the operation's tags and summary come from LegacyBaseController's class attributes, so
    // the whole hierarchy's declaring files are recorded as dependencies.
    generateDocument()->document;
    $engine->analyzeCount = 0;

    generateDocument()->document;
    expect($engine->analyzeCount)->toBe(0);

    // The disagreement is staged in this row's own cache rather than by rewriting the fixture on disk —
    // the reason spelled out on the enum-cast row above.
    $baseFile = (string) (new ReflectionClass(LegacyBaseController::class))->getFileName();
    $rewritten = 0;

    foreach (glob($dir.'/*.json') ?: [] as $entry) {
        /** @var array{dependencies?: list<array{file?: string, hash?: string}>} $decoded */
        $decoded = json_decode((string) file_get_contents($entry), true);
        $changed = false;

        foreach ($decoded['dependencies'] ?? [] as $index => $dependency) {
            if (($dependency['file'] ?? null) === $baseFile) {
                $decoded['dependencies'][$index]['hash'] = str_repeat('0', 64);
                $changed = true;
            }
        }

        if ($changed) {
            file_put_contents($entry, (string) json_encode($decoded));
            $rewritten++;
        }
    }

    // Half the claim: the base controller's file really is on the child route's dependency list.
    expect($rewritten)->toBeGreaterThan(0);

    generateDocument()->document;

    // The other half: an attribute added to the base retires the warm fragment.
    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('invalidates a fragment when an annotated custom rule class is edited', function (): void {
    fragmentCacheDir('fragments');
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

it('ignores its own cache directory so the fragments never reach the repository', function (): void {
    $dir = fragmentCacheDir('fragments');
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    generateDocument();

    expect(glob($dir.'/*.json') ?: [])->not->toBe([])
        ->and(file_get_contents($dir.'/.gitignore'))->toBe("*\n!.gitignore\n");
});

it('leaves a .gitignore the user has customised in the cache directory alone', function (): void {
    $dir = fragmentCacheDir('fragments');
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/.gitignore', "# mine\n");
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    generateDocument();

    expect(file_get_contents($dir.'/.gitignore'))->toBe("# mine\n");
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
