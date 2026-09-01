<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataResponseStatus;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\GuardedStatusData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\RouteStatusController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\RouteStatusData;
use Docuccino\Laravel\Tests\Support\ConditionalStatusEngine;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Illuminate\Routing\Router;

/**
 * A `calculateResponseStatus()` override that decides between two statuses on the route's NAME, resolved
 * per route. Left alone, the fold publishes the same body under BOTH statuses on every operation that
 * returns the class — a GET documenting a 201 the server can never send. The override is a real spatie
 * Data class whose body is walked as written; only the return type the engine folds is scripted.
 */
/** @param  list<int>  $statuses  what the engine folds the override's return type to */
function conditionalStatusResolverEngine(array $statuses, ?int &$traced = null): StubTypeEngine
{
    $traced = 0;
    $script = ConditionalStatusEngine::trace();

    return new StubTypeEngine(
        analyses: [ConditionalStatusEngine::SYMBOL => ConditionalStatusEngine::folds(...$statuses)],
        // Counted, because "only engage when the fold produced more than one status" is a claim about a
        // walk that must NOT happen — an assertion on the answer alone would pass with it happening.
        traces: [ConditionalStatusEngine::SYMBOL => static function (TraceVisitor $visitor) use ($script, &$traced): void {
            $traced++;
            $script($visitor);
        }],
    );
}

function conditionalStatusContext(StubTypeEngine $engine, string $method, ?string $name): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor([$method], '/api/things', $name),
        actionRef: new ActionRef('', RouteStatusController::class, 'store'),
        attributes: new AttributeSet,
        engine: $engine,
        document: new DocumentConfig('default', []),
    );
}

it('narrows a route-name override to the one status each route takes', function (string $method, ?string $name, array $expected): void {
    $engine = conditionalStatusResolverEngine([201, 200], $traced);
    $context = conditionalStatusContext($engine, $method, $name);

    expect((new DataResponseStatus)->resolveStatuses($context, RouteStatusData::class))->toBe($expected)
        ->and($context->components->diagnostics())->toBe([])
        ->and($traced)->toBe(1);
})->with([
    'the create route the pattern names' => ['POST', 'things.store', [201]],
    'a sibling POST the pattern does not name' => ['POST', 'things.publish', [200]],
    'a GET, where 201 is a status the server can never send' => ['GET', 'things.show', [200]],
    'a route with no name, which matches no pattern at all' => ['GET', null, [200]],
]);

it('records the override file, so editing the class invalidates the narrowed fragment', function (): void {
    $context = conditionalStatusContext(conditionalStatusResolverEngine([201, 200]), 'POST', 'things.store');

    (new DataResponseStatus)->resolveStatuses($context, RouteStatusData::class);

    expect($context->dependencies()->files())->toContain(ConditionalStatusEngine::file());
});

it('leaves a single folded status alone without tracing anything', function (): void {
    // One status is already right, and the walk that would narrow it costs a whole extra analysis.
    $engine = conditionalStatusResolverEngine([201], $traced);
    $context = conditionalStatusContext($engine, 'GET', 'things.show');

    expect((new DataResponseStatus)->resolveStatuses($context, RouteStatusData::class))->toBe([201])
        ->and($traced)->toBe(0);
});

it('keeps the union when the two folds disagree about what the arms are', function (): void {
    // The engine read 200|202 off the return type and the trace read 201/200 off the AST, so they were
    // not reading the same code. Publishing the trace's answer would document a status nothing proved.
    $engine = conditionalStatusResolverEngine([202, 200], $traced);
    $context = conditionalStatusContext($engine, 'POST', 'things.store');

    expect((new DataResponseStatus)->resolveStatuses($context, RouteStatusData::class))->toBe([200, 202])
        ->and($traced)->toBe(1);
});

it('publishes both statuses when the override is not a route decision at all', function (): void {
    // The degradation, unchanged: an override guarded on state the build cannot see really can answer
    // either status on any endpoint, so both are documented — and no diagnostic, because there is
    // nothing the author could do about it and a channel that fires here trains people to ignore it.
    $symbol = GuardedStatusData::class.'::calculateResponseStatus';
    $engine = new StubTypeEngine(
        analyses: [$symbol => ConditionalStatusEngine::folds(201, 200)],
        traces: [$symbol => TraceScript::forMethod(
            (string) (new ReflectionClass(GuardedStatusData::class))->getFileName(),
            GuardedStatusData::class,
            'calculateResponseStatus',
            ['request' => new ClassT('Illuminate\\Http\\Request')],
        )],
    );
    $context = conditionalStatusContext($engine, 'GET', 'things.show');

    expect((new DataResponseStatus)->resolveStatuses($context, GuardedStatusData::class))->toBe([200, 201])
        ->and($context->components->diagnostics())->toBe([]);
});

it('documents one status per operation once the routes are real', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->post('api/zz-things', [RouteStatusController::class, 'store'])->name('things.store');
    $router->post('api/zz-things/publish', [RouteStatusController::class, 'publish'])->name('things.publish');
    $router->get('api/zz-things', [RouteStatusController::class, 'show'])->name('things.show');

    app()->instance(TypeEngine::class, ConditionalStatusEngine::make());

    $paths = generateDocument()->document->toArray()['paths'];

    $successes = static fn (array $operation): array => array_values(array_filter(
        array_map('strval', array_keys($operation['responses'])),
        static fn (string $status): bool => str_starts_with($status, '2'),
    ));

    expect($successes($paths['/api/zz-things']['post']))->toBe(['201'])
        ->and($successes($paths['/api/zz-things/publish']['post']))->toBe(['200'])
        ->and($successes($paths['/api/zz-things']['get']))->toBe(['200']);
});
