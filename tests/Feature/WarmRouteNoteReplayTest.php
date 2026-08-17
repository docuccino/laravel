<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Context\RouteNotes;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\DynamicRenderer;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * A finding one route made and the whole DOCUMENT reports has to survive a warm fragment-cache hit — the
 * other half of {@see WarmColdEqualityTest}, which holds a warm build to a cold one's bytes and to the
 * diagnostics its own fragments carry. This one covers the diagnostics NO fragment carries: the
 * inferred-handler deferral summary, which is one line for however many routes threw through one render
 * callback, and is therefore raised by a document transformer reading an aggregate rather than by a route.
 *
 * Nothing about the mechanism is specific to that diagnostic — a route records a note, the note rides its
 * fragment, and the pipeline drains it into the collector on a warm hit exactly as on a cold build
 * ({@see RouteNotes}) — but it is the diagnostic that travels the path today, so it is the one pinned
 * here, in bytes.
 */

/** Two routes bound to the same model, so both throw through the one render callback. */
$deferring = static function (Router $router): void {
    $router->get('api/note-forms/{form}', [FormController::class, 'show']);
    $router->get('api/note-archives/{form}', [FormController::class, 'show']);
};

/**
 * The renderer registered on the booted handler, plus an engine that answers for it with a plain
 * `Response` — no JSON body and no constant status, which is what the real {@see DynamicRenderer} body
 * folds to and what makes the tier defer.
 *
 * @return callable(): TypeEngine
 */
$deferringEngine = static function (): callable {
    $renderer = new DynamicRenderer;

    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable($renderer);

    $function = new ReflectionFunction(Closure::fromCallable($renderer));
    $symbol = (new CallableRef(
        (string) $function->getFileName(),
        $renderer::class,
        $function->getName(),
        0,
        $function->getParameters()[0]->getName(),
        ModelNotFoundException::class,
    ))->symbol();

    return static fn (): TypeEngine => WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\Response'), new SourceLocation(''))]),
    ]);
};

it('replays a document-level summary two routes contributed to, once, on a warm build', function () use ($deferring, $deferringEngine): void {
    $engine = $deferringEngine();

    // Warm/cold equality covers bytes, diagnostics and that the warm build really did hit the cache.
    $warm = assertWarmEqualsCold($deferring, $deferring, $engine);

    $summaries = array_values(array_filter(
        $warm->diagnostics,
        static fn (Diagnostic $d): bool => $d->code === 'inferred-handler.too-dynamic',
    ));

    // Both routes deferred through one callback, so the aggregate is ONE line naming it — a replay that
    // reported per route would agree with a cold build on nothing, and one that double-counted would
    // publish the exception type twice.
    expect($summaries)->toHaveCount(1)
        ->and($summaries[0]->message)->toContain(DynamicRenderer::class.'::__invoke')
        ->and($summaries[0]->message)->toContain('1 exception type(s): '.ModelNotFoundException::class);
});

it('reports it from a build that asked the engine NOTHING', function () use ($deferring, $deferringEngine): void {
    // The load-bearing half. Reporting the summary by rebuilding the routes would satisfy every equality
    // assertion above and throw the cache away, so this pins the two together: not one analysis, and the
    // summary still there. `analyzeCount` is zero only when EVERY route came back warm — one that rebuilt
    // would re-record its note and hide a replay that does nothing.
    $engine = $deferringEngine();
    $dir = fragmentCacheDir('notes');

    try {
        localityBuild($deferring, $engine);

        $warm = localityBuild($deferring, $engine, $counting);

        expect($counting)->toBeInstanceOf(CountingTypeEngine::class)
            ->and($counting->analyzeCount)->toBe(0)
            ->and(diagnosticsCoded($warm->diagnostics, 'inferred-handler.too-dynamic'))->toHaveCount(1);
    } finally {
        removeFragmentCacheDir($dir);
    }
});

it('never reports one document’s finding against the next document in the same process', function () use ($deferringEngine): void {
    // The aggregate is container-`scoped`, which outlives a build: `docuccino:export` with no argument
    // builds every configured document in one process, and the second one must not inherit the first's.
    $engine = $deferringEngine();
    app()->instance(TypeEngine::class, $engine());

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->get('api/note-forms/{form}', [FormController::class, 'show']);
    $deferred = generateDocument();

    // The same process, a document whose one route binds no model and so throws through no handler.
    $router->setRoutes(new RouteCollection);
    $router->get('api/note-plain', [FormController::class, 'index']);
    $quiet = generateDocument();

    expect(diagnosticsCoded($deferred->diagnostics, 'inferred-handler.too-dynamic'))->toHaveCount(1)
        ->and(diagnosticsCoded($quiet->diagnostics, 'inferred-handler.too-dynamic'))->toBe([]);
});

it('emits the deferral-summary document and its diagnostics byte-identically, cold and warm', function () use ($deferring, $deferringEngine): void {
    $engine = $deferringEngine();

    $warm = assertWarmEqualsCold($deferring, $deferring, $engine);

    // The cache directories the helper used are gone by now, so this build is genuinely cold.
    config()->set('docuccino.cache.enabled', false);
    $cold = localityBuild($deferring, $engine);

    foreach (['warm' => $warm, 'cold' => $cold] as $build) {
        assertGolden('workbench-handler-deferral.uir.json', (new UirEmitter)->emit($build->document));
        assertGolden(
            'workbench-handler-deferral.diagnostics.json',
            json_encode(diagnosticRecords($build->diagnostics), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }
});
