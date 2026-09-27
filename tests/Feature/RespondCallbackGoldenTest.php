<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallCondition;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Workbench\App\Http\Controllers\FormController;

/**
 * The document an application gets when `$exceptions->respond()` rewrites the errors of its `api/*` routes
 * into problem+json and hands every other route's back unchanged: the api route publishes the callback's
 * body, the other route the framework's, and nothing is minted for the body the api route no longer sends.
 * Byte-locked, warm as well as cold, and held to the document the same api route gets from a render
 * callback returning that body — the two are one contract spelled two ways.
 */
function respondGoldenRewrite(): ClassT
{
    return new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([
            new ArrayShapeField('type', new LiteralT('about:blank')),
            new ArrayShapeField('title', ScalarT::string()),
            new ArrayShapeField('status', ScalarT::int()),
        ]),
        new UnknownT('status not folded'),
        new LiteralT('application/problem+json'),
    ]);
}

it('publishes the rewrite for the routes the callback rewrites and the rendered body elsewhere, byte-identically', function (): void {
    setBuild('documents.default.routes.include', ['api/*', 'portal/*']);

    $symbol = registerRespondCallback(
        static fn (Response $response, Throwable $e, Request $request): Response => $response,
        ModelNotFoundException::class,
    );

    $engine = static fn (): TypeEngine => WorkbenchEngine::make([$symbol => new ActionAnalysis(returns: [
        new ReturnSite(respondGoldenRewrite(), new SourceLocation(''), conditions: [new CallCondition('request', 'is', ['api/*'], true)]),
        new ReturnSite(new ClassT(Response::class), new SourceLocation(''), returnsParameter: 'response', conditions: [new CallCondition('request', 'is', ['api/*'], false)]),
    ])]);

    $routes = static function (Router $router): void {
        $router->get('api/probe-forms/{form}', [FormController::class, 'show']);
        $router->get('portal/probe-forms/{form}', [FormController::class, 'show']);
    };

    $warm = assertWarmEqualsCold($routes, $routes, $engine);
    $document = emittedArray($warm);

    assertGolden('workbench-respond-callback.uir.json', (new UirEmitter)->emit($warm->document));

    expect(array_keys(resolveResponse($document, $document['paths']['/api/probe-forms/{form}']['get']['responses']['404'])['content'] ?? []))
        ->toBe(['application/problem+json'])
        ->and(array_keys(resolveResponse($document, $document['paths']['/portal/probe-forms/{form}']['get']['responses']['404'])['content'] ?? []))
        ->toBe(['application/json']);
});

it('publishes the api route exactly as a render callback returning the same body would', function (): void {
    $symbol = registerRespondCallback(
        static fn (Response $response, Throwable $e, Request $request): Response => $response,
        ModelNotFoundException::class,
    );
    $routes = static fn (Router $router) => $router->get('api/probe-forms/{form}', [FormController::class, 'show']);

    $responded = emittedArray(localityBuild($routes, static fn (): TypeEngine => WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(respondGoldenRewrite(), new SourceLocation(''))]),
    ])));

    // The same body, from the handler's other hook. A fresh app, so the respond callback is gone.
    $this->refreshApplication();
    $render = registerRenderCallback(
        static fn (NotFoundHttpException $e) => response()->json([], 404),
        ModelNotFoundException::class,
    );
    $rendered = emittedArray(localityBuild($routes, static fn (): TypeEngine => WorkbenchEngine::make([
        $render => new ActionAnalysis(returns: [new ReturnSite(respondGoldenRewrite(), new SourceLocation(''))]),
    ])));

    expect($responded['paths']['/api/probe-forms/{form}']['get']['responses']['404'])
        ->toBe($rendered['paths']['/api/probe-forms/{form}']['get']['responses']['404'])
        ->and($responded['components'] ?? [])->toBe($rendered['components'] ?? []);
});
