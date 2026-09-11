<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Engine\EnginePackage;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Workbench\App\Http\Controllers\BindingController;
use Workbench\App\Http\Controllers\FormController;

/**
 * The fragment cache's staleness boundary: the facts that reach emitted bytes without touching a
 * dependency file — a route's name, which engine is installed and how it is configured, the app's
 * locked dependency set — all have to key the cache, and a supported command has to be able to empty
 * the store.
 */
afterEach(function (): void {
    removeFragmentCacheDirs('staleness');
});

it('rebuilds a renamed route rather than serving its old operationId', function (): void {
    fragmentCacheDir('staleness');
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    app('router')->get('api/renamable', [FormController::class, 'index'])->name('forms.alpha');
    $cold = generateDocument()->document->toArray();

    // The route name is the default operationId, and renaming touches no file the fragment depends on.
    app('router')->get('api/renamable', [FormController::class, 'index'])->name('forms.beta');
    $warm = generateDocument()->document->toArray();

    expect($cold['paths']['/api/renamable']['get']['operationId'] ?? null)->toBe('forms.alpha')
        ->and($warm['paths']['/api/renamable']['get']['operationId'] ?? null)->toBe('forms.beta');
});

it('rebuilds a route that gained withTrashed rather than serving its untrashed answer', function (): void {
    fragmentCacheDir('staleness');
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    app('router')->get('api/trashable/{form}', [FormController::class, 'show']);
    $cold = generateDocument()->document->toArray();

    // `->withTrashed()` puts a note and a fact on every bound parameter, and — like the rename above —
    // changes nothing else the key already carries and no file the fragment depends on.
    app('router')->get('api/trashable/{form}', [FormController::class, 'show'])->withTrashed();
    $warm = generateDocument()->document->toArray();

    $coldParameter = $cold['paths']['/api/trashable/{form}']['get']['parameters'][0];
    $warmParameter = $warm['paths']['/api/trashable/{form}']['get']['parameters'][0];

    expect($coldParameter['x-docuccino']['facts']['routeBinding'])->toBe(['key' => 'id'])
        ->and($coldParameter['description'])->not->toContain('trashed')
        ->and($warmParameter['x-docuccino']['facts']['routeBinding']['withTrashed'])->toBeTrue()
        ->and($warmParameter['description'])->toContain('trashed');
});

it('rebuilds a route that named a binding column rather than serving its route-key answer', function (): void {
    fragmentCacheDir('staleness');
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    app('router')->get('api/bindable/{form}', [FormController::class, 'show']);
    $cold = generateDocument()->document->toArray();

    // Laravel parses `:slug` OUT of `uri()`, so this route has the same method, URI, name, action and
    // middleware as the one above while typing its parameter off a different column entirely.
    app('router')->get('api/bindable/{form:slug}', [FormController::class, 'show']);
    $warm = generateDocument()->document->toArray();

    expect($cold['paths']['/api/bindable/{form}']['get']['parameters'][0]['schema']['type'])->toBe('integer')
        ->and($warm['paths']['/api/bindable/{form}']['get']['parameters'][0]['schema']['type'])->toBe('string');
});

it('rebuilds a route that gained scoped bindings rather than serving its unscoped answer', function (): void {
    fragmentCacheDir('staleness');
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    app('router')->get('api/scopable/{ledger}/entries/{entry}', [BindingController::class, 'showEntry']);
    $cold = generateDocument()->document->toArray();

    // `->scopeBindings()` is a flag on the route. It changes what the server accepts — a child now has
    // to belong to its parent — and touches neither the URI, the action, the middleware nor any file
    // the fragment depends on, so it has to fold itself into the key like `->withTrashed()` does.
    app('router')->get('api/scopable/{ledger}/entries/{entry}', [BindingController::class, 'showEntry'])
        ->scopeBindings();
    $warm = generateDocument()->document->toArray();

    $path = '/api/scopable/{ledger}/entries/{entry}';
    $coldEntry = pathParameter($cold['paths'][$path]['get'], 'entry');
    $warmEntry = pathParameter($warm['paths'][$path]['get'], 'entry');

    expect($coldEntry['x-docuccino']['facts']['routeBinding'])->not->toHaveKey('scopedTo')
        ->and($warmEntry['x-docuccino']['facts']['routeBinding']['scopedTo'])->toBe('ledger');
});

it('rebuilds a route that prevented scoped bindings rather than serving its scoped answer', function (): void {
    fragmentCacheDir('staleness');
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    // A child naming its own column scopes itself, with nothing on the route saying so.
    app('router')->get('api/preventable/{ledger}/entries/{entry:title}', [BindingController::class, 'showEntryByTitle']);
    $cold = generateDocument()->document->toArray();

    // `->withoutScopedBindings()` switches that off. Laravel parses the `:title` out of the URI, so
    // the two routes agree on method, URI, name, action and middleware, and the flag is the whole
    // difference — the other direction of the row above it, and the one that loosens what the server
    // accepts rather than tightening it.
    app('router')->get('api/preventable/{ledger}/entries/{entry:title}', [BindingController::class, 'showEntryByTitle'])
        ->withoutScopedBindings();
    $warm = generateDocument()->document->toArray();

    $path = '/api/preventable/{ledger}/entries/{entry}';
    $coldEntry = pathParameter($cold['paths'][$path]['get'], 'entry');
    $warmEntry = pathParameter($warm['paths'][$path]['get'], 'entry');

    expect($coldEntry['x-docuccino']['facts']['routeBinding']['scopedTo'])->toBe('ledger')
        ->and($coldEntry['description'])->toContain('Scoped to `{ledger}`')
        ->and($warmEntry['x-docuccino']['facts']['routeBinding'])->not->toHaveKey('scopedTo')
        ->and($warmEntry['description'] ?? '')->not->toContain('Scoped to');
});

it('rebuilds a route whose parameter gained a binder of the application\'s own', function (): void {
    fragmentCacheDir('staleness');
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    app('router')->get('api/bindable-custom/{custom}', [BindingController::class, 'showBound']);
    $cold = generateDocument()->document->toArray();

    // A registered binder is global router state a service provider set up. It decides what the
    // segment holds, and nothing about the route itself moves when one appears.
    app('router')->bind('custom', static fn (string $value): string => $value);
    $warm = generateDocument()->document->toArray();

    $path = '/api/bindable-custom/{custom}';

    expect(pathParameter($cold['paths'][$path]['get'], 'custom')['schema']['type'])->toBe('integer')
        ->and(pathParameter($warm['paths'][$path]['get'], 'custom')['schema']['type'])->toBe('string');
});

it('never serves inference-free fragments once the engine package is installed', function (): void {
    fragmentCacheDir('staleness');

    // Engine-less build: the adapter degrades to the NullTypeEngine, so every fragment is written
    // without a single inferred fact.
    app()->instance(EnginePackage::class, new EnginePackage(static fn (string $class): bool => false));
    app()->instance(TypeEngine::class, new NullTypeEngine);
    generateDocument();

    // Install the engine and rebuild against the same warm store.
    app()->instance(EnginePackage::class, new EnginePackage);
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
    $warm = (new UirEmitter)->emit(generateDocument()->document);

    // The truth: the same build with a cold store.
    fragmentCacheDir('staleness');
    $cold = (new UirEmitter)->emit(generateDocument()->document);

    expect($warm)->toBe($cold);
});

it('invalidates fragments when the engine descend paths change', function (): void {
    fragmentCacheDir('staleness');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument();
    $engine->analyzeCount = 0;

    // Widening what the engine descends into changes what inference can recover for any route.
    setBuild('engine.project_paths', ['app', 'modules']);
    generateDocument();

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('keeps the fragments warm when only the engine memory limit changes', function (): void {
    fragmentCacheDir('staleness');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument();
    $engine->analyzeCount = 0;

    // A process ceiling cannot change a documented byte — `--memory-limit` must not cost a rebuild.
    setBuild('engine.memory_limit', '2G');
    generateDocument();

    expect($engine->analyzeCount)->toBe(0);
});

it('invalidates fragments when the Passport endpoint path changes', function (): void {
    fragmentCacheDir('staleness');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument();
    $engine->analyzeCount = 0;

    // passport.path is the prefix of every emitted oauth2 flow URL, exactly like app.url beside it.
    config()->set('passport.path', 'oauth2');
    generateDocument();

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('empties the fragment store on docuccino:clear --fragments', function (): void {
    $dir = fragmentCacheDir('staleness');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument();
    expect(glob($dir.'/*.json') ?: [])->not->toBe([]);

    $this->artisan('docuccino:clear', ['--fragments' => true])->assertSuccessful();

    expect(glob($dir.'/*.json') ?: [])->toBe([]);

    $engine->analyzeCount = 0;
    generateDocument();

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});
