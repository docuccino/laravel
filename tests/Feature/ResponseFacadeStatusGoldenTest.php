<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\ArchiveFacadeController;

/**
 * The document an application gets for responses made through the `Response` facade — JSON, no-content,
 * plain and view responses with a status of their own, one whose status a helper reads off the case it is
 * handed, and one sent with the framework's 200 — and through its root-namespace alias, an application's
 * own and a real-time facade of the factory. Byte-locked, warm as well as cold; the scripted returns
 * are what the real engine recovers from the fixture app's same calls, which the last test holds them to.
 *
 * @return array<string, DType> action → the response it returns
 */
function responseFacadeStatusReturns(): array
{
    $sent = static fn (DType $payload, int $status): ClassT => new ClassT('Illuminate\\Http\\JsonResponse', [$payload, new LiteralT($status)]);
    $error = static fn (string $error): ArrayShapeT => new ArrayShapeT([new ArrayShapeField('error', new LiteralT($error))]);

    // The facade hands each call to the response factory, so each is sent as `response()->…` sends it.
    return [
        'refuse' => $sent($error('locked'), 422),
        'reset' => $sent(new VoidT, 205),
        'purge' => $sent(new VoidT, 410),
        'tombstone' => $sent(new ClassT('Illuminate\\Contracts\\View\\View'), 410),
        // The status and the body member the helper reads off the case are the call site's.
        'expire' => $sent($error('Gone'), 410),
        'status' => $sent(new ArrayShapeT([new ArrayShapeField('ok', new LiteralT(true))]), 200),
        // A root-namespace alias, the framework's or the application's own, is the facade it names.
        'refuseAliased' => $sent($error('locked'), 422),
        'purgeAliased' => $sent(new VoidT, 410),
        'refuseByReply' => $sent($error('locked'), 422),
        // A real-time facade of the factory is a facade of it too.
        'refuseInRealTime' => $sent($error('locked'), 422),
        'purgeInRealTime' => $sent(new VoidT, 410),
    ];
}

/** @return callable(): TypeEngine */
function responseFacadeStatusEngine(): callable
{
    $analyses = [];
    foreach (responseFacadeStatusReturns() as $action => $type) {
        $analyses[ArchiveFacadeController::class.'::'.$action] = new ActionAnalysis(returns: [new ReturnSite($type, new SourceLocation(''))]);
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function responseFacadeStatusRoutes(): callable
{
    return static function (Router $router): void {
        $router->post('api/archive-facade/refuse', [ArchiveFacadeController::class, 'refuse']);
        $router->post('api/archive-facade/reset', [ArchiveFacadeController::class, 'reset']);
        $router->delete('api/archive-facade/purge', [ArchiveFacadeController::class, 'purge']);
        $router->get('api/archive-facade/tombstone', [ArchiveFacadeController::class, 'tombstone']);
        $router->post('api/archive-facade/expire', [ArchiveFacadeController::class, 'expire']);
        $router->get('api/archive-facade/status', [ArchiveFacadeController::class, 'status']);
        $router->post('api/archive-facade/refuse-aliased', [ArchiveFacadeController::class, 'refuseAliased']);
        $router->delete('api/archive-facade/purge-aliased', [ArchiveFacadeController::class, 'purgeAliased']);
        $router->post('api/archive-facade/refuse-by-reply', [ArchiveFacadeController::class, 'refuseByReply']);
        $router->post('api/archive-facade/refuse-in-real-time', [ArchiveFacadeController::class, 'refuseInRealTime']);
        $router->delete('api/archive-facade/purge-in-real-time', [ArchiveFacadeController::class, 'purgeInRealTime']);
    };
}

it('publishes the status a response made through the facade is sent with, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(responseFacadeStatusRoutes(), responseFacadeStatusRoutes(), responseFacadeStatusEngine());

    assertGolden('workbench-response-facade-status.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (string $action, string $fixtureMethod): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $real = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/ResponseFacadeStatusController.php',
        'App\\Http\\Controllers\\ResponseFacadeStatusController',
        $fixtureMethod,
    ));

    expect(array_map(static fn (ReturnSite $site): DType => $site->type, $real->returns))
        ->toEqual([responseFacadeStatusReturns()[$action]]);
})->with([
    'Response::json($body, 422)' => ['refuse', 'json'],
    'Response::noContent(205)' => ['reset', 'noContent'],
    'Response::make(\'\', 410)' => ['purge', 'make'],
    'Response::view($view, [], 410)' => ['tombstone', 'view'],
    '$this->faulted(Case), reading $fault->value' => ['expire', 'helperCase'],
    'Response::json($body), the framework\'s 200' => ['status', 'jsonOk'],
    '\\Response::json($body, 422)' => ['refuseAliased', 'alias'],
    '\\Response::make(\'\', 410)' => ['purgeAliased', 'aliasMade'],
    '\\Reply::json($body, 422), the application\'s own alias' => ['refuseByReply', 'appAlias'],
])->group('fixture');

it('scripts what the real engine recovers from the fixture app’s calls through a real-time facade', function (string $action, string $fixtureMethod): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $real = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/RealTimeFacadeController.php',
        'App\\Http\\Controllers\\RealTimeFacadeController',
        $fixtureMethod,
    ));

    expect(array_map(static fn (ReturnSite $site): DType => $site->type, $real->returns))
        ->toEqual([responseFacadeStatusReturns()[$action]]);
})->with([
    'ResponseFactory::json($body, 422)' => ['refuseInRealTime', 'json'],
    '\\Facades\\…\\ResponseFactory::make(\'\', 410)' => ['purgeInRealTime', 'make'],
])->group('fixture');
