<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\ExportStreamController;

/**
 * The document an application gets for streams, files and redirects sent with a status of their own —
 * `response()->stream($cb, 202)`, `streamJson($rows, 206)`, a choice of two, `new BinaryFileResponse($f, 206)`,
 * `redirect()->to($url, 301)`, `back(303)` — beside a stream sent with the framework's 200, a redirect with
 * its 302, one of each whose status nothing can read, and two streams whose headers and download name are
 * passed by argument name. Byte-locked, warm as well as cold; the scripted
 * returns are what the real engine recovers from the fixture app's same calls, which the last test holds
 * them to.
 *
 * @return array<string, DType> action → the response it returns
 */
function streamStatusReturns(): array
{
    $stream = 'Symfony\\Component\\HttpFoundation\\StreamedResponse';
    $json = 'Symfony\\Component\\HttpFoundation\\StreamedJsonResponse';
    $redirect = 'Illuminate\\Http\\RedirectResponse';
    // The status a call states rides on the class it makes, where a `JsonResponse` carries its own.
    $stated = static fn (string $class, DType $status): ClassT => new ClassT($class, [new UnknownT('payload not folded'), $status]);

    return [
        'queue' => $stated($stream, new LiteralT(202)),
        'rows' => $stated($json, new LiteralT(206)),
        'slice' => $stated($json, UnionT::of([new LiteralT(200), new LiteralT(206)])),
        'report' => $stated('Symfony\\Component\\HttpFoundation\\BinaryFileResponse', new LiteralT(206)),
        'export' => new ClassT($stream),
        'named' => new ClassT($stream),
        'ledger' => new ClassT($stream),
        'relay' => $stated($stream, new UnknownT('status not folded')),
        'moved' => $stated($redirect, new LiteralT(301)),
        'finished' => $stated($redirect, new LiteralT(303)),
        'latest' => new ClassT($redirect),
        'follow' => $stated($redirect, new UnknownT('status not folded')),
    ];
}

/** @return callable(): TypeEngine */
function streamStatusEngine(): callable
{
    $analyses = [];
    foreach (streamStatusReturns() as $action => $type) {
        $analyses[ExportStreamController::class.'::'.$action] = new ActionAnalysis(returns: [new ReturnSite($type, new SourceLocation(''))]);
    }

    // The calls the media type is read off, as the engine's walk hands them to the visitor.
    $factory = 'Illuminate\\Contracts\\Routing\\ResponseFactory';
    $traces = [
        'queue' => "response()->stream(fn () => null, 202, ['Content-Type' => 'text/csv'])",
        'rows' => "response()->streamJson(['rows' => []], 206)",
        'slice' => "response()->streamJson(['rows' => []], \$partial ? 206 : 200)",
        'export' => "response()->stream(fn () => null, 200, ['Content-Type' => 'text/csv'])",
        'relay' => "response()->stream(fn () => null, \$request->integer('status'), ['Content-Type' => 'text/csv'])",
        'named' => "response()->stream(fn () => null, headers: ['Content-Type' => 'text/csv'])",
        'ledger' => "\\Illuminate\\Support\\Facades\\Response::streamDownload(fn () => null, name: 'ledger.csv', headers: ['Content-Type' => 'text/csv'])",
    ];
    $scripted = [];
    foreach ($traces as $action => $call) {
        $scripted[ExportStreamController::class.'::'.$action] = TraceScript::forChain($call, $factory, "ExportStreamController/$action.php");
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses, traceOverrides: $scripted);
}

function streamStatusRoutes(): callable
{
    return static function (Router $router): void {
        $router->post('api/exports/queue', [ExportStreamController::class, 'queue']);
        $router->get('api/exports/rows', [ExportStreamController::class, 'rows']);
        $router->get('api/exports/slice', [ExportStreamController::class, 'slice']);
        $router->get('api/exports/report', [ExportStreamController::class, 'report']);
        $router->get('api/exports/export', [ExportStreamController::class, 'export']);
        $router->get('api/exports/relay', [ExportStreamController::class, 'relay']);
        $router->get('api/exports/named', [ExportStreamController::class, 'named']);
        $router->get('api/exports/ledger', [ExportStreamController::class, 'ledger']);
        $router->get('api/exports/moved', [ExportStreamController::class, 'moved']);
        $router->post('api/exports/finished', [ExportStreamController::class, 'finished']);
        $router->get('api/exports/latest', [ExportStreamController::class, 'latest']);
        $router->get('api/exports/follow', [ExportStreamController::class, 'follow']);
    };
}

it('publishes the status a stream, file or redirect is sent with, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(streamStatusRoutes(), streamStatusRoutes(), streamStatusEngine());

    assertGolden('workbench-stream-status.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (string $action, string $fixture, string $fixtureMethod): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $real = ActionAnalysis::fromArray(FixtureRunner::analyze(
        "app/Http/Controllers/$fixture.php",
        'App\\Http\\Controllers\\'.$fixture,
        $fixtureMethod,
    ));

    expect(array_map(static fn (ReturnSite $site): DType => $site->type, $real->returns))
        ->toEqual([streamStatusReturns()[$action]]);
})->with([
    'response()->stream($cb, 202, $headers)' => ['queue', 'StreamStatusController', 'stream'],
    'response()->streamJson($rows, 206)' => ['rows', 'StreamStatusController', 'streamJson'],
    'response()->streamJson($rows, $partial ? 206 : 200)' => ['slice', 'StreamStatusController', 'choice'],
    'new BinaryFileResponse($file, 206)' => ['report', 'StreamStatusController', 'constructedFile'],
    'response()->stream($cb, 200, $headers), the framework\'s own 200' => ['export', 'StreamStatusController', 'streamOk'],
    'response()->stream($cb, $request->integer(…))' => ['relay', 'StreamStatusController', 'unread'],
    'response()->stream($cb, headers: $headers)' => ['named', 'FileDeliveryController', 'namedExport'],
    'Response::streamDownload($cb, name: $name, headers: $headers)' => ['ledger', 'FileDeliveryController', 'namedLedger'],
    'redirect()->to($url, 301)' => ['moved', 'RedirectStatusController', 'to'],
    'back(303)' => ['finished', 'RedirectStatusController', 'back'],
    'redirect($url), the framework\'s 302' => ['latest', 'RedirectStatusController', 'plain'],
    'redirect()->to($url, $request->integer(…))' => ['follow', 'RedirectStatusController', 'unread'],
])->group('fixture');
