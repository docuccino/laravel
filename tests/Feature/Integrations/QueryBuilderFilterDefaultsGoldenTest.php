<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\FilterDefaultsController;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Query Builder filter defaults in every shape Spatie applies one, locked in emitted bytes: the
 * enum-case golden held one filter, a scalar on a list-typed filter, and nothing else.
 */
it('emits Query Builder filter defaults byte-identical to their committed golden', function (): void {
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        traceOverrides: [
            FilterDefaultsController::class.'::index' => TraceScript::forMethod(
                (string) (new ReflectionClass(FilterDefaultsController::class))->getFileName(),
                FilterDefaultsController::class,
                'index',
                receiverFqcn: 'Spatie\\QueryBuilder\\QueryBuilder',
            ),
        ],
    );

    $result = localityBuild(
        static fn (Router $router) => $router->get('api/zz-filter-defaults', [FilterDefaultsController::class, 'index']),
        $engine,
    );

    assertGolden('qb-filter-defaults.uir.json', (new UirEmitter)->emit($result->document));
});
