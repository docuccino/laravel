<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\ExportFaultController;

/**
 * The document an application gets for a fault answered with its enum case's backing value as the status,
 * through a helper handed the case and with the case chosen at run time. Byte-locked, warm as well as cold;
 * the scripted returns are what the real engine recovers from the fixture app's same calls, which the last
 * test holds them to.
 *
 * @return array<string, DType> action → the response it returns
 */
function helperCaseStatusReturns(): array
{
    $json = static fn (DType $error, DType $status): ClassT => new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([new ArrayShapeField('error', $error)]),
        $status,
    ]);
    $names = UnionT::of([new LiteralT('Gone'), new LiteralT('Locked')]);

    return [
        // The helper reads whichever case it is handed, and each call hands it one: that case's value is
        // the status sent, and its name the body.
        'gone' => $json(new LiteralT('Gone'), new LiteralT(410)),
        'locked' => $json(new LiteralT('Locked'), new LiteralT(423)),
        // Gone answers 410 and Locked 423: no one code is true of every request, so none is read.
        'report' => $json($names, new UnknownT('status not folded')),
    ];
}

/** @return callable(): TypeEngine */
function helperCaseStatusEngine(): callable
{
    $analyses = [];
    foreach (helperCaseStatusReturns() as $action => $type) {
        $analyses[ExportFaultController::class.'::'.$action] = new ActionAnalysis(returns: [new ReturnSite($type, new SourceLocation(''))]);
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function helperCaseStatusRoutes(): callable
{
    return static function (Router $router): void {
        $router->get('api/exports/{export}/gone', [ExportFaultController::class, 'gone']);
        $router->get('api/exports/{export}/locked', [ExportFaultController::class, 'locked']);
        $router->get('api/exports/{export}/fault', [ExportFaultController::class, 'report']);
    };
}

it('publishes the status a helper reads off the case it is handed, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(helperCaseStatusRoutes(), helperCaseStatusRoutes(), helperCaseStatusEngine());

    assertGolden('workbench-helper-case-status.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (string $action, string $fixtureMethod): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $real = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/CaseStatusResponseController.php',
        'App\\Http\\Controllers\\CaseStatusResponseController',
        $fixtureMethod,
    ));

    expect(array_map(static fn (ReturnSite $site): DType => $site->type, $real->returns))
        ->toEqual([helperCaseStatusReturns()[$action]]);
})->with([
    'a helper building new JsonResponse()' => ['gone', 'helperValue'],
    'a helper building response()->json()' => ['locked', 'helperValueJson'],
    'chosen at run time' => ['report', 'valueDisagreeing'],
])->group('fixture');
