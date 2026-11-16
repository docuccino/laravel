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
use Workbench\App\Http\Controllers\DeferredExportController;

/**
 * The document an application gets for a response built into a local whose status or media type a later
 * statement sets — `$response->setStatusCode(202);` on a line of its own — for a JSON, plain, streamed and
 * redirect response and one thrown for the framework to send, beside a status set on one branch, set twice,
 * set before the local is replaced, set by a helper handed the response, a header set by one, and set from
 * the request.
 * Byte-locked, warm as well as cold; the scripted returns are what the real engine recovers from the fixture
 * app's same calls, which the last test holds them to.
 *
 * @return array<string, DType> action → the response it returns
 */
function statementStatusReturns(): array
{
    $json = 'Illuminate\\Http\\JsonResponse';
    $queued = static fn (bool $queued): ArrayShapeT => new ArrayShapeT([new ArrayShapeField('queued', new LiteralT($queued))]);
    $sent = static fn (DType $body, int $status): ClassT => new ClassT($json, [$body, new LiteralT($status)]);

    return [
        // The status a statement sets on its own line, as it would chained onto the call that built it.
        'queue' => $sent($queued(true), 202),
        'note' => new ClassT($json, [new LiteralT('queued'), new LiteralT(202), new LiteralT('text/html')]),
        'stream' => new ClassT('Symfony\\Component\\HttpFoundation\\StreamedResponse', [new UnknownT('payload not folded'), new LiteralT(202)]),
        'forward' => new ClassT('Illuminate\\Http\\RedirectResponse', [new UnknownT('payload not folded'), new LiteralT(303)]),
        // Set on one branch: sent with either.
        'defer' => new ClassT($json, [$queued(true), UnionT::of([new LiteralT(200), new LiteralT(202)])]),
        // The last one to run is the one sent.
        'retry' => $sent($queued(true), 202),
        'restart' => $sent(new ArrayShapeT([new ArrayShapeField('created', new LiteralT(true))]), 201),
        // A helper the build does not follow may have changed anything, so nothing it was built with stands.
        'hand' => new ClassT($json, [new UnknownT('payload not folded'), new UnknownT('status not folded')]),
        // A helper that sets only a header leaves the status and the body it was built with.
        'stamp' => $sent($queued(true), 202),
        'relay' => new ClassT($json, [$queued(true), new UnknownT('status not folded')]),
        'csv' => new ClassT($json, [new LiteralT('id,total'), new LiteralT(202), new LiteralT('text/csv')]),
        'refuse' => $sent($queued(false), 409),
    ];
}

/** @return callable(): TypeEngine */
function statementStatusEngine(): callable
{
    $analyses = [];
    foreach (statementStatusReturns() as $action => $type) {
        $analyses[DeferredExportController::class.'::'.$action] = new ActionAnalysis(returns: [new ReturnSite($type, new SourceLocation(''))]);
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function statementStatusRoutes(): callable
{
    return static function (Router $router): void {
        $router->post('api/deferred/queue', [DeferredExportController::class, 'queue']);
        $router->post('api/deferred/note', [DeferredExportController::class, 'note']);
        $router->get('api/deferred/stream', [DeferredExportController::class, 'stream']);
        $router->post('api/deferred/forward', [DeferredExportController::class, 'forward']);
        $router->post('api/deferred/defer', [DeferredExportController::class, 'defer']);
        $router->post('api/deferred/retry', [DeferredExportController::class, 'retry']);
        $router->post('api/deferred/restart', [DeferredExportController::class, 'restart']);
        $router->post('api/deferred/hand', [DeferredExportController::class, 'hand']);
        $router->post('api/deferred/stamp', [DeferredExportController::class, 'stamp']);
        $router->post('api/deferred/relay', [DeferredExportController::class, 'relay']);
        $router->get('api/deferred/csv', [DeferredExportController::class, 'csv']);
        $router->post('api/deferred/refuse', [DeferredExportController::class, 'refuse']);
    };
}

it('publishes the status a later statement sets on a returned local, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(statementStatusRoutes(), statementStatusRoutes(), statementStatusEngine());

    assertGolden('workbench-statement-status.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (string $action, string $fixtureMethod): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $real = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/StatementStatusController.php',
        'App\\Http\\Controllers\\StatementStatusController',
        $fixtureMethod,
    ));

    expect(array_map(static fn (ReturnSite $site): DType => $site->type, $real->returns))
        ->toEqual([statementStatusReturns()[$action]]);
})->with([
    'response()->json(…), then setStatusCode(202)' => ['queue', 'json'],
    'response($text), then setStatusCode(202)' => ['note', 'plain'],
    'response()->stream($cb), then setStatusCode(202)' => ['stream', 'stream'],
    'redirect($url), then setStatusCode(303)' => ['forward', 'redirect'],
    'setStatusCode(202) on one branch' => ['defer', 'branch'],
    'setStatusCode(201), then setStatusCode(202)' => ['retry', 'twice'],
    'setStatusCode(202), then the local replaced with a 201' => ['restart', 'replaced'],
    'a helper handed the response sets 202' => ['hand', 'helper'],
    'a helper handed the response sets a header' => ['stamp', 'helperHeader'],
    'setStatusCode($request->integer(…))' => ['relay', 'unread'],
    'header(\'Content-Type\', \'text/csv\')' => ['csv', 'header'],
    'thrown in an HttpResponseException after setStatusCode(409)' => ['refuse', 'thrown'],
])->group('fixture');
