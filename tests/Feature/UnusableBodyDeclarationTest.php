<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\SchemaClass\PreferencesRouteController;
use Docuccino\Laravel\Tests\Fixtures\SchemaClass\ReadOnlyFilterRequest;
use Docuccino\Laravel\Tests\Fixtures\SchemaClass\SharedPreferencesRequest;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * A `#[BodyParameter]` on a request TYPE is honoured — and at a read verb the same rules become QUERY
 * parameters, so it reaches nothing there. That third answer is document-wide: a type bound to a write
 * route somewhere is doing its job, and reporting it from the read route would fire where nothing can
 * be done. Each route records what it saw on its fragment; the verdict is reached once at assembly, so
 * a warm build reconstructs it rather than replaying it.
 *
 * The real recovery path — a routed FormRequest whose rules the engine traces, through the integration
 * extension and the whole pipeline — rather than a hand-built context, because the observation is made
 * where the source class is known to be a request type and nowhere else.
 */

/** @return callable(): TypeEngine */
function preferenceRouteEngine(): callable
{
    $rules = new ArrayShapeT([new ArrayShapeField('nickname', new LiteralT('required|string'))]);
    $location = new SourceLocation('');

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: [
        ReadOnlyFilterRequest::class.'::rules' => new ActionAnalysis(returns: [new ReturnSite($rules, $location)]),
        SharedPreferencesRequest::class.'::rules' => new ActionAnalysis(returns: [new ReturnSite($rules, $location)]),
    ]);
}

/** The read route the unusable declaration is bound to. */
function readOnlyPreferenceRoutes(): callable
{
    return static function (Router $router): void {
        $router->get('api/preference-filters', [PreferencesRouteController::class, 'index']);
    };
}

/** The shared type at both verbs, plus the read-only type — so exactly one of the two is reportable. */
function sharedPreferenceRoutes(): callable
{
    return static function (Router $router): void {
        $router->get('api/preferences', [PreferencesRouteController::class, 'list']);
        $router->post('api/preferences', [PreferencesRouteController::class, 'store']);
        $router->get('api/preference-filters', [PreferencesRouteController::class, 'index']);
    };
}

/**
 * What the shared type was reported as, if anything. Every row that expects silence about it carries a
 * type beside it that IS reported, so silence about the shared one is the reconciliation working rather
 * than the whole mechanism being dead.
 *
 * @param  list<Diagnostic>  $diagnostics
 * @return list<string>
 */
function preferenceReports(array $diagnostics): array
{
    return array_map(
        static fn (Diagnostic $d): string => str_contains($d->message, SharedPreferencesRequest::class)
            ? SharedPreferencesRequest::class
            : $d->message,
        diagnosticsCoded($diagnostics, 'attribute.schema-class-unusable'),
    );
}

it('reports a #[BodyParameter] on a type no operation documents a body from', function (): void {
    $result = localityBuild(readOnlyPreferenceRoutes(), preferenceRouteEngine());

    $reported = diagnosticsCoded($result->diagnostics, 'attribute.schema-class-unusable');

    expect($reported)->toHaveCount(1)
        ->and($reported[0]->severity)->toBe(Severity::Warning)
        ->and($reported[0]->message)->toContain(ReadOnlyFilterRequest::class)
        ->and($reported[0]->message)->toContain('#[BodyParameter]')
        // The declaration really did reach nothing: the read verb published the rules as query
        // parameters, and no request body was written for it to be in.
        ->and($result->document->toArray()['paths']['/api/preference-filters']['get'])
        ->not->toHaveKey('requestBody');
});

/**
 * The load-bearing row, and the whole reason the verdict is not per-route. One type answers both verbs;
 * the read route observes it unusable and the write route observes it used, and a reconciliation that
 * dropped the `used` half would tell the author their correct declaration does nothing.
 */
it('says nothing about a type bound to BOTH a read route and a write route', function (): void {
    $result = localityBuild(sharedPreferenceRoutes(), preferenceRouteEngine());

    $body = $result->document->toArray()['paths']['/api/preferences']['post']['requestBody'] ?? null;
    $reported = diagnosticsCoded($result->diagnostics, 'attribute.schema-class-unusable');

    // The read-only type in the same build IS reported, so the silence about the shared one is the
    // reconciliation standing it down and not the mechanism being switched off.
    expect($reported)->toHaveCount(1)
        ->and($reported[0]->message)->toContain(ReadOnlyFilterRequest::class)
        ->and(preferenceReports($result->diagnostics))->not->toContain(SharedPreferencesRequest::class)
        // …and it is not silent because nothing was recovered: the write route really did write the
        // declaration into the component the read route could not use.
        ->and($body)->toBeArray()
        ->and($result->document->toArray()['components']['schemas']['SharedPreferencesRequest']['properties'])
        ->toHaveKey('overrides');
});

it('reports it once for a type bound to several read routes', function (): void {
    $result = localityBuild(static function (Router $router): void {
        $router->get('api/preference-filters', [PreferencesRouteController::class, 'index']);
        $router->get('api/preference-filters/archive', [PreferencesRouteController::class, 'index']);
    }, preferenceRouteEngine());

    // Two routes found the same fact; the author has one declaration to fix.
    expect(diagnosticsCoded($result->diagnostics, 'attribute.schema-class-unusable'))->toHaveCount(1);
});

it('emits the same bytes and the same diagnostics warm as cold', function (): void {
    $routes = readOnlyPreferenceRoutes();

    // Warms the cache, rebuilds against it, rebuilds cold, and holds the two to identical bytes AND
    // identical diagnostics — both directions, so a warm build that reported fewer fails here.
    $warm = assertWarmEqualsCold($routes, $routes, preferenceRouteEngine());

    // Equal-and-both-empty would prove nothing.
    expect(diagnosticsCoded($warm->diagnostics, 'attribute.schema-class-unusable'))->toHaveCount(1);
});

/**
 * The soundness claim the design rests on: the verdict is reconstructed from the fragments rather than
 * replayed, so a build in which EVERY route came back warm still reaches it. A reporter that only fired
 * while a route was being built would satisfy the byte comparison above and go silent here.
 */
it('reaches the verdict on a build that asked the engine nothing', function (): void {
    $routes = readOnlyPreferenceRoutes();
    $engine = preferenceRouteEngine();
    $dir = fragmentCacheDir('unusable-body');

    try {
        localityBuild($routes, $engine);

        $warm = localityBuild($routes, $engine, $counting);

        expect($counting?->analyzeCount)->toBe(0)
            ->and(diagnosticsCoded($warm->diagnostics, 'attribute.schema-class-unusable'))->toHaveCount(1);
    } finally {
        removeFragmentCacheDir($dir);
    }
});

/**
 * And it stays document-wide across a warm hit: both read routes come back from the cache while the
 * write route is built fresh. The reportable observation then exists nowhere but a cached fragment, so
 * a fragment that had lost it would go silent about a declaration that really does reach nothing — and
 * the shared type's cached observation is still the one the fresh write route stands down.
 */
it('reports from a warm read route while a write route is built beside it', function (): void {
    $engine = preferenceRouteEngine();
    $dir = fragmentCacheDir('unusable-body-mixed');
    $coldDir = null;

    try {
        // Warm the read routes only — the write route below is the one thing built fresh.
        localityBuild(static function (Router $router): void {
            $router->get('api/preferences', [PreferencesRouteController::class, 'list']);
            $router->get('api/preference-filters', [PreferencesRouteController::class, 'index']);
        }, $engine);

        $mixed = localityBuild(sharedPreferenceRoutes(), $engine, $warmEngine);

        // The same route set with nothing cached, purely to say what "warm" was worth here.
        $coldDir = fragmentCacheDir('unusable-body-mixed-cold');
        localityBuild(sharedPreferenceRoutes(), $engine, $coldEngine);

        $reported = diagnosticsCoded($mixed->diagnostics, 'attribute.schema-class-unusable');

        expect($reported)->toHaveCount(1)
            ->and($reported[0]->message)->toContain(ReadOnlyFilterRequest::class)
            // …and the shared type stays silent, because the write route beside it was built fresh.
            ->and(preferenceReports($mixed->diagnostics))->not->toContain(SharedPreferencesRequest::class)
            // …and the read routes really did come back warm rather than being rebuilt into the report.
            ->and($warmEngine)->toBeInstanceOf(CountingTypeEngine::class)
            ->and($coldEngine)->toBeInstanceOf(CountingTypeEngine::class)
            ->and($warmEngine->analyzeCount)->toBeLessThan($coldEngine->analyzeCount);
    } finally {
        removeFragmentCacheDir($dir);

        if ($coldDir !== null) {
            removeFragmentCacheDir($coldDir);
        }
    }
});

/**
 * The dangerous direction of the same thing: the WRITE route comes back warm and the read route is
 * built fresh, so the fact that stands the report down exists only on the cached fragment. A warm
 * fragment that had lost its observation would publish a false positive here, telling the author their
 * load-bearing declaration does nothing.
 */
it('lets a warm write route stand down a read route built beside it', function (): void {
    $engine = preferenceRouteEngine();
    $dir = fragmentCacheDir('unusable-body-warm-write');

    try {
        localityBuild(static function (Router $router): void {
            $router->post('api/preferences', [PreferencesRouteController::class, 'store']);
        }, $engine);

        $mixed = localityBuild(sharedPreferenceRoutes(), $engine);

        expect(diagnosticsCoded($mixed->diagnostics, 'attribute.schema-class-unusable'))->toHaveCount(1)
            ->and(preferenceReports($mixed->diagnostics))->not->toContain(SharedPreferencesRequest::class)
            // …and the write route really did come back warm rather than being rebuilt into agreement.
            ->and($mixed->document->toArray()['paths']['/api/preferences']['post']['requestBody'])->toBeArray();
    } finally {
        removeFragmentCacheDir($dir);
    }
});

it('names no absolute path in what it publishes', function (): void {
    $result = localityBuild(readOnlyPreferenceRoutes(), preferenceRouteEngine());

    foreach (diagnosticsCoded($result->diagnostics, 'attribute.schema-class-unusable') as $diagnostic) {
        expect($diagnostic)->toBeInstanceOf(Diagnostic::class)
            ->and($diagnostic->message)->not->toContain(dirname(__DIR__, 4))
            ->and($diagnostic->help)->not->toContain(dirname(__DIR__, 4));
    }
});
