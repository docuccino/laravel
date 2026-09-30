<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FilterClassListController;

/**
 * What a list endpoint whose filters are self-documenting custom filter CLASSES publishes, in bytes —
 * the population no golden stood in for. A class-level `#[QueryParameter]` is named by the filter's
 * registration, so one leaving the name off types its filter exactly as one writing a name does; one
 * PHP cannot construct is reported, and its filter types off the body as an unannotated class's would.
 */
$engine = static fn (): TypeEngine => WorkbenchEngine::make(traceOverrides: [
    // Read out of the controller's real file, so an edit to the application is what the test sees.
    FilterClassListController::class.'::index' => TraceScript::forMethod(
        (string) (new ReflectionClass(FilterClassListController::class))->getFileName(),
        FilterClassListController::class,
        'index',
        receiverFqcn: 'Spatie\\QueryBuilder\\QueryBuilder',
    ),
]);

$routes = static function (Router $router): void {
    $router->get('api/filter-classes', [FilterClassListController::class, 'index']);
};

it('emits the filter-class document and its diagnostics byte-identically', function () use ($engine, $routes): void {
    app()->instance(TypeEngine::class, $engine());
    /** @var Router $router */
    $router = app('router');
    $routes($router);

    $result = generateDocument(static function (array $raw): array {
        $raw['info'] = ['title' => 'Filter-class API', 'version' => '1.0.0'];
        $raw['routes'] = ['include' => ['api/filter-classes']];

        return $raw;
    });

    $parameters = paramsByName($result->document->toArray()['paths']['/api/filter-classes']['get']);

    // The contract, stated before the bytes: the nameless declaration types and describes its filter,
    // the named one is applied under the registration's name and not its own, and the unreadable one
    // leaves the body's integer `score` cast — with one report naming the class.
    expect($parameters['filter[band]']['schema']['type'])->toBe('integer')
        ->and($parameters['filter[band]']['description'])->toBe('The lowest score band to include.')
        ->and($parameters['filter[popular]']['schema']['type'])->toBe('integer')
        ->and($parameters)->not->toHaveKey('ignored')
        ->and($parameters['filter[score]']['schema']['type'])->toBe('integer')
        ->and($parameters['filter[score]']['description'] ?? '')->not->toContain('Never published')
        ->and(array_map(static fn ($d): string => $d->code, diagnosticsCoded($result->diagnostics, 'attribute.unreadable')))->toBe(['attribute.unreadable'])
        ->and(diagnosticsCoded($result->diagnostics, 'query-builder.untyped-filter'))->toBe([]);

    assertGolden('workbench-filter-classes.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden(
        'workbench-filter-classes.diagnostics.json',
        json_encode(diagnosticRecords($result->diagnostics), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );
});

it('reports the unreadable filter attribute on a warm build exactly as on a cold one', function () use ($engine, $routes): void {
    // The report is raised while the route is built, so a warm hit drops it unless it rides the fragment.
    $warm = assertWarmEqualsCold($routes, $routes, $engine);

    expect(diagnosticsCoded($warm->diagnostics, 'attribute.unreadable'))->toHaveCount(1);
});
