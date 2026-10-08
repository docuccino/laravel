<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\StatusTextMarkerT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeCondition;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Integrations\InferredHandler\ReceivedException;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Http\Controllers\FormController;
use Workbench\App\Http\Controllers\ValidationController;

/**
 * The document an application gets when `$exceptions->respond()` rewrites every JSON error into problem+json
 * and passes anything else through by its class — `if (! $response instanceof JsonResponse) { return
 * $response; }`. Every error the framework renders as JSON publishes the rewrite alone: a binding's 404 and a
 * validated request's 422. An `HttpResponseException` sends whatever response it carries, so the guard says
 * nothing about it and its rendered body stands beside the rewrite. Byte-locked, warm as well as cold; the
 * scripted analysis is what the real engine recovers from the fixture app's own callback, which the last
 * test holds it to.
 */
function respondTypeGuardAnalysis(): ActionAnalysis
{
    $problem = new ClassT(JsonResponse::class, [
        new ArrayShapeT([
            new ArrayShapeField('type', new LiteralT('about:blank')),
            // The callback reads both off the rendered response: the reason phrase and the code it is sent with.
            new ArrayShapeField('title', new StatusTextMarkerT(ScalarT::string(), new LiteralT('Error'))),
            new ArrayShapeField('status', new StatusMarkerT),
            new ArrayShapeField('errors', new ListT(new UnknownT('mixed')), optional: true),
        ]),
        new UnknownT('status not folded'),
        new LiteralT('application/problem+json'),
    ]);

    return new ActionAnalysis(returns: [
        new ReturnSite(new ClassT(Response::class), new SourceLocation(''), returnsParameter: 'response', typeConditions: [new TypeCondition('response', JsonResponse::class, false)]),
        new ReturnSite($problem, new SourceLocation(''), typeConditions: [new TypeCondition('response', JsonResponse::class, true)]),
    ]);
}

it('publishes the rewrite alone for every error the framework renders as JSON, byte-identically', function (): void {
    setBuild('documents.default.routes.include', ['api/*']);

    $callback = static fn (Response $response, Throwable $e, Request $request): Response => $response;
    $guarded = respondTypeGuardAnalysis();

    $callables = [];
    foreach ([ModelNotFoundException::class, ValidationException::class, HttpResponseException::class] as $thrown) {
        $callables[registerRespondCallback($callback, $thrown)] = $guarded;
    }

    $engine = static fn (): TypeEngine => WorkbenchEngine::make($callables, analysisOverrides: [
        FormController::class.'::index' => new ActionAnalysis(
            returns: [new ReturnSite(new ListT(new ClassT('Workbench\\App\\Data\\FormData')), new SourceLocation(''))],
            throws: [new ThrownException(HttpResponseException::class, 409, [], ThrowConfidence::Certain, ThrowDisposition::Signal)],
        ),
    ]);

    $routes = static function (Router $router): void {
        $router->get('api/probe-forms/{form}', [FormController::class, 'show']);
        $router->post('api/probe-tickets', [ValidationController::class, 'store']);
        $router->get('api/probe-handoffs', [FormController::class, 'index']);
    };

    $warm = assertWarmEqualsCold($routes, $routes, $engine);
    $document = emittedArray($warm);

    assertGolden('workbench-respond-type-guard.uir.json', (new UirEmitter)->emit($warm->document));

    $media = static fn (string $path, string $verb, string $status): array => array_keys(resolveResponse($document, $document['paths'][$path][$verb]['responses'][$status] ?? [])['content'] ?? []);

    expect($media('/api/probe-forms/{form}', 'get', '404'))->toBe(['application/problem+json'])
        ->and($media('/api/probe-tickets', 'post', '422'))->toBe(['application/problem+json'])
        ->and($media('/api/probe-handoffs', 'get', '409'))->toBe(['application/json', 'application/problem+json']);
});

it('scripts what the real engine recovers from the fixture app’s own callback', function (string $thrown): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $source = (string) file_get_contents(FixtureRunner::path('app/Exceptions/RespondCallbacks.php'));
    $line = 0;
    foreach (explode("\n", $source) as $index => $text) {
        if (str_contains($text, 'public function jsonGuarded(')) {
            $line = $index + 3;
        }
    }
    expect($line)->toBeGreaterThan(3);

    $real = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/RespondCallbacks.php',
        '',
        '',
        line: $line,
        param: 'e',
        narrowType: ReceivedException::byRespondCallback($thrown) ?? '',
        every: true,
    ));

    // Everything but where each return is written.
    $facts = static fn (ActionAnalysis $analysis): array => array_map(static function (ReturnSite $site): array {
        $facts = $site->toArray();
        unset($facts['location']);

        return $facts;
    }, $analysis->returns);

    expect($facts($real))->toBe($facts(respondTypeGuardAnalysis()));
})->with([ModelNotFoundException::class, ValidationException::class, HttpResponseException::class])->group('fixture');
