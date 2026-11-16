<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\Pagination\PageSizeBoundsController;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * `jsonPaginate()` page sizes whose default and ceiling come from different places, locked in emitted
 * bytes: every recorded one had a default no larger than its maximum.
 */
it('emits page sizes bounded apart from their defaults byte-identical to their committed golden', function (): void {
    $file = (string) (new ReflectionClass(PageSizeBoundsController::class))->getFileName();
    $methods = ['cappedBelowConfig', 'defaultAboveCap', 'withinBounds'];

    $traces = [];
    foreach ($methods as $method) {
        $traces[PageSizeBoundsController::class.'::'.$method] = TraceScript::forMethod(
            $file,
            PageSizeBoundsController::class,
            $method,
            receiverFqcn: 'Illuminate\\Database\\Eloquent\\Builder',
        );
    }

    $result = localityBuild(
        static function (Router $router) use ($methods): void {
            foreach ($methods as $method) {
                $router->get('api/zz-page-size/'.$method, [PageSizeBoundsController::class, $method]);
            }
        },
        static fn (): TypeEngine => WorkbenchEngine::make(traceOverrides: $traces),
    );

    assertGolden('page-size-bounds.uir.json', (new UirEmitter)->emit($result->document));
});
