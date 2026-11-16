<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\ResponseChainController;

/**
 * The document an application gets for a response a fluent chain changes after it is built — its content,
 * data, JSONP callback, encoding, a cookie or cache headers set, a download's or a redirect's own setters, or
 * a setter nothing documents called — for
 * a chain on an application's response subclass, one of which overrides the status setter, and for a `new
 * JsonResponse` built into a local, returned as it is or after its data is set. Byte-locked, warm as well as
 * cold; the scripted returns are what the real engine recovers from the fixture app's same calls, which the
 * last test holds them to.
 *
 * @return array<string, DType> action → the response it returns
 */
function responseChainReturns(): array
{
    $json = 'Illuminate\\Http\\JsonResponse';
    $responses = 'Workbench\\App\\Http\\Responses\\';
    $queued = new ArrayShapeT([new ArrayShapeField('queued', new LiteralT(true))]);
    $accepted = new ArrayShapeT([new ArrayShapeField('accepted', new LiteralT(true))]);
    $sent = static fn (DType $body, int $status): ClassT => new ClassT($json, [$body, new LiteralT($status)]);
    $unread = new UnknownT('payload not folded');

    return [
        // A link replacing the body leaves the status: the 202 the response was built with is the one sent.
        'note' => new ClassT($json, [new LiteralT('accepted'), new LiteralT(202), new LiteralT('text/html')]),
        'accept' => $sent($accepted, 202),
        'settle' => $sent($accepted, 202),
        // A JSONP callback, or an encoding that sends `[]` as `{}`, leaves no body that can be named.
        'padded' => $sent($unread, 202),
        'batch' => $sent($unread, 202),
        // A pretty-printed encoding or a cookie changes nothing documented.
        'pretty' => $sent($queued, 202),
        'remember' => $sent($queued, 202),
        // Marked not modified: a 304 with nothing in it.
        'unchanged' => $sent(new VoidT, 304),
        // Cache headers change nothing documented.
        'cached' => $sent($queued, 202),
        // A download's and a redirect's own setters change nothing documented: the file the factory built, sent
        // with a 200, and the 303 the redirect was built with.
        'export' => new ClassT('Symfony\\Component\\HttpFoundation\\BinaryFileResponse'),
        'flash' => new ClassT('Illuminate\\Http\\RedirectResponse', [$unread, new LiteralT(303)]),
        // `prepare()` may change anything for the request it is handed.
        'prepared' => new ClassT($json, [$unread, new UnknownT('status not folded')]),
        // A chain on an application's subclass is read over what its constructor hands the framework's.
        'removed' => $sent(new ArrayShapeT([new ArrayShapeField('message', new LiteralT('This resource is gone.'))]), 404),
        'relocated' => new ClassT($responses.'MovedResponse', [$unread, new LiteralT(308)]),
        // …unless it overrides the setter, which may do anything.
        'locked' => new ClassT($json, [$unread, new UnknownT('status not folded')]),
        // A local holding `new JsonResponse` is the response it was built with.
        'built' => $sent($queued, 202),
        'refilled' => $sent($accepted, 202),
    ];
}

/** @return callable(): TypeEngine */
function responseChainEngine(): callable
{
    $analyses = [];
    foreach (responseChainReturns() as $action => $type) {
        $analyses[ResponseChainController::class.'::'.$action] = new ActionAnalysis(returns: [new ReturnSite($type, new SourceLocation(''))]);
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function responseChainRoutes(): callable
{
    return static function (Router $router): void {
        $router->post('api/chains/note', [ResponseChainController::class, 'note']);
        $router->post('api/chains/accept', [ResponseChainController::class, 'accept']);
        $router->post('api/chains/settle', [ResponseChainController::class, 'settle']);
        $router->post('api/chains/padded', [ResponseChainController::class, 'padded']);
        $router->post('api/chains/batch', [ResponseChainController::class, 'batch']);
        $router->post('api/chains/pretty', [ResponseChainController::class, 'pretty']);
        $router->post('api/chains/remember', [ResponseChainController::class, 'remember']);
        $router->get('api/chains/unchanged', [ResponseChainController::class, 'unchanged']);
        $router->post('api/chains/cached', [ResponseChainController::class, 'cached']);
        $router->get('api/chains/export', [ResponseChainController::class, 'export']);
        $router->post('api/chains/flash', [ResponseChainController::class, 'flash']);
        $router->post('api/chains/prepared', [ResponseChainController::class, 'prepared']);
        $router->get('api/chains/removed', [ResponseChainController::class, 'removed']);
        $router->get('api/chains/relocated', [ResponseChainController::class, 'relocated']);
        $router->get('api/chains/locked', [ResponseChainController::class, 'locked']);
        $router->post('api/chains/built', [ResponseChainController::class, 'built']);
        $router->post('api/chains/refilled', [ResponseChainController::class, 'refilled']);
    };
}

it('publishes the response a fluent chain leaves, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(responseChainRoutes(), responseChainRoutes(), responseChainEngine());

    assertGolden('workbench-response-chain.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (string $action, string $fixtureMethod): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $real = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/ResponseChainController.php',
        'App\\Http\\Controllers\\ResponseChainController',
        $fixtureMethod,
    ));

    // The fixture app's response classes are the workbench's under another namespace; JSON escapes the
    // separators it is named with.
    $named = static fn (DType $type): string => str_replace('Workbench\\\\App\\\\', 'App\\\\', json_encode($type->toArray(), JSON_THROW_ON_ERROR));

    expect(array_map(static fn (ReturnSite $site): string => $named($site->type), $real->returns))
        ->toBe([$named(responseChainReturns()[$action])]);
})->with([
    'response($text, 202)->setContent($text)' => ['note', 'content'],
    'response()->json(…, 202)->setData(…)' => ['accept', 'data'],
    'response()->json(…)->setData(…)->setStatusCode(202)' => ['settle', 'dataThenStatus'],
    'setCallback($request->query(…))' => ['padded', 'callback'],
    'setEncodingOptions(JSON_FORCE_OBJECT)' => ['batch', 'forceObject'],
    'setEncodingOptions(JSON_PRETTY_PRINT)' => ['pretty', 'pretty'],
    'cookie(…)' => ['remember', 'cookie'],
    'setNotModified()' => ['unchanged', 'unknownLink'],
    'setMaxAge(60)->setEtag(…)->setPublic()' => ['cached', 'cached'],
    'download(…)->deleteFileAfterSend()' => ['export', 'download'],
    'redirect($url, 303)->with(…)->withErrors(…)->…' => ['flash', 'redirectFlash'],
    'prepare($request)' => ['prepared', 'prepared'],
    '(new GoneJsonResponse)->setStatusCode(404)' => ['removed', 'subclass'],
    '(new MovedResponse($url))->setStatusCode(308)' => ['relocated', 'subclassRedirect'],
    'setStatusCode(404) on a subclass overriding it' => ['locked', 'overridden'],
    '$response = new JsonResponse(…, 202); return $response;' => ['built', 'localJson'],
    'new JsonResponse(…, 202) in a local, then setData(…)' => ['refilled', 'localData'],
])->group('fixture');
