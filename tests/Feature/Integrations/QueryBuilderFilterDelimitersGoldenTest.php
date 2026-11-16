<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\FilterDelimitersController;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Query Builder filters with a separator of their own, locked in emitted bytes: every recorded filter
 * split on the configured delimiter. The second document is the same filters with
 * `filter_value_splitting_enabled` turned off, which Spatie reads from 7.2.1: an older install splits
 * regardless, so there it is the first document byte for byte.
 */
it('emits Query Builder filters with their own delimiter byte-identical to their committed golden', function (bool $splitting, string $golden): void {
    config()->set('query-builder.filter_value_splitting_enabled', $splitting);

    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        traceOverrides: [
            FilterDelimitersController::class.'::index' => TraceScript::forMethod(
                (string) (new ReflectionClass(FilterDelimitersController::class))->getFileName(),
                FilterDelimitersController::class,
                'index',
                receiverFqcn: 'Spatie\\QueryBuilder\\QueryBuilder',
            ),
        ],
    );

    $result = localityBuild(
        static fn (Router $router) => $router->get('api/zz-filter-delimiters', [FilterDelimitersController::class, 'index']),
        $engine,
    );

    $readsSwitch = version_compare((string) InstalledVersions::getVersion('spatie/laravel-query-builder'), '7.2.1', '>=');

    assertGolden($readsSwitch ? $golden : 'qb-filter-delimiters.uir.json', (new UirEmitter)->emit($result->document));
})->with([
    'splitting on' => [true, 'qb-filter-delimiters.uir.json'],
    'splitting off' => [false, 'qb-filter-delimiters-unsplit.uir.json'],
]);
