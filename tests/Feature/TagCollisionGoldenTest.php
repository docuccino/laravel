<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Admin\ReportController as AdminReportController;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Api\DaybookController;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Api\JournalController;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Api\LedgerController;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Api\ReportController as ApiReportController;
use Illuminate\Routing\Router;

/*
 * Three ways operations of more than one route land under one default tag, side by side in one build:
 * two controllers that share a short name by accident, one controller registered two ways, and two
 * controllers `tags.map` sends to one tag on purpose. `tags.name-collision` is only worth reading if it
 * fires on the first and stays quiet on the other two, so the build pins all three together.
 */
function tagCollisionPopulation(): GenerationResult
{
    setBuild('documents.default.tags.map', ['Journal' => 'Accounts', 'Daybook' => 'Accounts']);

    return localityBuild(static function (Router $router): void {
        $router->get('api/zz-api-reports', [ApiReportController::class, 'index']);
        $router->get('api/zz-admin-reports', [AdminReportController::class, 'index']);

        // Laravel's route registrar spells the class with a leading backslash; `Route::get()` does not.
        $router->get('api/zz-ledgers', [LedgerController::class, 'index']);
        $router->name('ledgers.archive')->get('api/zz-ledgers/archive', [LedgerController::class, 'index']);

        $router->get('api/zz-journal', [JournalController::class, 'index']);
        $router->get('api/zz-daybook', [DaybookController::class, 'index']);
    });
}

it('emits the tag-collision population and its document byte-identically', function (): void {
    $result = tagCollisionPopulation();

    assertGolden('workbench-tag-collisions.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden(
        'workbench-tag-collisions.diagnostics.json',
        json_encode(diagnosticRecords(diagnosticsCoded($result->diagnostics, 'tags.name-collision')), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );
});
