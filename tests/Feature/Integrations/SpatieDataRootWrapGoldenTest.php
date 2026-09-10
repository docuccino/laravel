<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AuthorData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\HelperContextProblemData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedTransformDisabledData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\RootWrapController;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * The root envelope of a spatie Data response, locked in emitted bytes. The golden corpus otherwise
 * carries no laravel-data class at all, so nothing in it could ever move when the envelope read
 * changes — and a change to what a tier publishes that moves no golden has not been shown safe, it has
 * shown the corpus is silent about it.
 *
 * The class it carries is the one the envelope read used to get wrong: it disables wrapping on a
 * NESTED transformation, which spatie confines to the transformation it is handed, so the server sends
 * `{"data": …}` and the document has to as well. One route, its own document, so no existing golden
 * moves and this one moves only when this route's own answer does.
 */
it('emits a spatie Data root envelope byte-identical to its committed golden', function (): void {
    bootLaravelData('data');

    $location = new SourceLocation('');
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            NestedTransformDisabledData::class => new ClassMetadata(NestedTransformDisabledData::class, [
                new PropertyMetadata('id', ScalarT::int()),
                new PropertyMetadata('author', new ClassT(AuthorData::class)),
            ]),
            AuthorData::class => new ClassMetadata(AuthorData::class, [
                new PropertyMetadata('name', ScalarT::string()),
                new PropertyMetadata('email', ScalarT::string()),
            ]),
        ],
        analysisOverrides: [
            RootWrapController::class.'::show' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT(NestedTransformDisabledData::class), $location)],
            ),
        ],
    );

    $result = localityBuild(
        static fn (Router $router) => $router->get('api/zz-wrapped', [RootWrapController::class, 'show']),
        $engine,
    );

    $emitted = (new UirEmitter)->emit($result->document);

    assertGolden('spatie-root-wrap.uir.json', $emitted);

    // What the golden is for, said out loud: the envelope is a real key in the emitted response
    // schema, and the component under it stays flat so a shared `$ref` never carries one caller's.
    $response = $result->document->toArray()['paths']['/api/zz-wrapped']['get']['responses'];
    $schema = $response['200']['content']['application/json']['schema'];

    expect(array_keys($schema['properties']))->toBe(['data'])
        ->and($schema['required'])->toBe(['data']);
});

afterEach(fn () => removeFragmentCacheDirs('spatie-wrap'));

it('reports an unsettled envelope on a warm fragment-cache build too', function (): void {
    bootLaravelData('data');
    fragmentCacheDir('spatie-wrap');

    $location = new SourceLocation('');
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            HelperContextProblemData::class => new ClassMetadata(HelperContextProblemData::class, [
                new PropertyMetadata('type', ScalarT::string()),
                new PropertyMetadata('status', ScalarT::int()),
            ]),
        ],
        analysisOverrides: [
            RootWrapController::class.'::problem' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT(HelperContextProblemData::class), $location)],
            ),
        ],
    );

    $routes = static fn (Router $router) => $router->get('api/zz-unsettled', [RootWrapController::class, 'problem']);

    $cold = localityBuild($routes, $engine);
    $warm = localityBuild($routes, $engine, $counting);

    // Fewer diagnostics on a warm build is a silent degradation, not a saving — the envelope is
    // published under doubt either way, so the report has to survive the cache hit with it. The
    // engine count is what makes the row worth having: a second build that rebuilt would replay
    // nothing and pass this anyway.
    expect($counting)->toBeInstanceOf(CountingTypeEngine::class)
        ->and($counting->analyzeCount)->toBe(0)
        ->and((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document))
        ->and(diagnosticsCoded($cold->diagnostics, 'spatie-data.root-wrap-unsettled'))->not->toBeEmpty()
        ->and(diagnosticsCoded($warm->diagnostics, 'spatie-data.root-wrap-unsettled'))
        ->toEqual(diagnosticsCoded($cold->diagnostics, 'spatie-data.root-wrap-unsettled'));
});
