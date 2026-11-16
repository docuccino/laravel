<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
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
use Workbench\App\Http\Controllers\ArchiveController;

/**
 * The document an application gets for plain responses the factory makes — `response('', 410)`, its status
 * a class constant, `response()->make()` and `response()->view()` — beside one sent with the framework's
 * 200. Byte-locked, warm as well as cold; the scripted returns are what the real engine recovers from the
 * fixture app's same calls, which the last test holds them to.
 *
 * @return array<string, DType> action → the response it returns
 */
function responseHelperStatusReturns(): array
{
    $made = static fn (DType $payload, int $status): ClassT => new ClassT('Illuminate\\Http\\JsonResponse', [$payload, new LiteralT($status)]);

    return [
        // Sent with the status the call names, and an empty content is no body.
        'purge' => $made(new VoidT, 410),
        // A string is sent as it is rather than as JSON, labelled `text/html`; the status still is read.
        'retire' => new ClassT('Illuminate\\Http\\JsonResponse', [new LiteralT('archived'), new LiteralT(410), new LiteralT('text/html')]),
        'lock' => $made(new VoidT, 423),
        // A view is rendered as HTML whatever status carries it.
        'tombstone' => $made(new ClassT('Illuminate\\Contracts\\View\\View'), 410),
        // The framework's 200, with the text it sends.
        'ping' => new ClassT('Illuminate\\Http\\JsonResponse', [new LiteralT('created'), new LiteralT(200), new LiteralT('text/html')]),
    ];
}

/** @return callable(): TypeEngine */
function responseHelperStatusEngine(): callable
{
    $analyses = [];
    foreach (responseHelperStatusReturns() as $action => $type) {
        $analyses[ArchiveController::class.'::'.$action] = new ActionAnalysis(returns: [new ReturnSite($type, new SourceLocation(''))]);
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function responseHelperStatusRoutes(): callable
{
    return static function (Router $router): void {
        $router->delete('api/archive/purge', [ArchiveController::class, 'purge']);
        $router->post('api/archive/retire', [ArchiveController::class, 'retire']);
        $router->post('api/archive/lock', [ArchiveController::class, 'lock']);
        $router->get('api/archive/tombstone', [ArchiveController::class, 'tombstone']);
        $router->get('api/archive/ping', [ArchiveController::class, 'ping']);
    };
}

it('publishes the status a plain response is made with, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(responseHelperStatusRoutes(), responseHelperStatusRoutes(), responseHelperStatusEngine());

    assertGolden('workbench-response-helper-status.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (string $action, string $fixtureMethod): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $real = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/ResponseHelperStatusController.php',
        'App\\Http\\Controllers\\ResponseHelperStatusController',
        $fixtureMethod,
    ));

    expect(array_map(static fn (ReturnSite $site): DType => $site->type, $real->returns))
        ->toEqual([responseHelperStatusReturns()[$action]]);
})->with([
    'response(\'\', 410)' => ['purge', 'literal'],
    'response($body, Response::HTTP_GONE)' => ['retire', 'constant'],
    'response()->make(\'\', Case->value)' => ['lock', 'factoryMake'],
    'response()->view($view, [], 410)' => ['tombstone', 'view'],
    'response($body), the framework\'s 200' => ['ping', 'plainOk'],
])->group('fixture');
