<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Integrations\InferredHandler\ReceivedException;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Http\Controllers\FormController;

/**
 * What an application gets when `$exceptions->respond()` rewrites every error of its `api/*` routes into
 * RFC 9457 problem+json and passes the rest through — the one-line arrow function the Laravel docs' hook
 * invites. Both halves are real: the callback's returns come from the real engine reading the fixture
 * app's own callback, and the document around them is the full pipeline, with each error produced the way
 * the framework produces it — a binding's 404, a validated request's 422, the auth middleware's 401 and
 * the throttle's 429.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * The `RespondCallbacks::$method` callback's returns as the real engine reads them for each thrown type,
 * keyed and narrowed the way the finalizer asks for them.
 *
 * @param  list<class-string>  $thrown
 * @return array<string, ActionAnalysis>
 */
function respondAnalyses(array $thrown, string $method = 'pathGated'): array
{
    $source = (string) file_get_contents(FixtureRunner::path('app/Exceptions/RespondCallbacks.php'));
    $line = 0;
    foreach (explode("\n", $source) as $index => $text) {
        if (str_contains($text, 'public function '.$method.'(')) {
            $line = $index + 3;
        }
    }
    expect($line)->toBeGreaterThan(3);

    $analyses = [];
    foreach ($thrown as $fqcn) {
        $symbol = registerRespondCallback(
            static fn (Response $response, Throwable $e, Request $request): Response => $response,
            $fqcn,
        );
        $analyses[$symbol] = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
            'app/Exceptions/RespondCallbacks.php',
            '',
            '',
            line: $line,
            param: 'e',
            narrowType: ReceivedException::byRespondCallback($fqcn) ?? '',
            every: true,
        ));
    }

    return $analyses;
}

it('publishes every api route error as the problem+json body the callback builds', function (string $path, string $method, string $status, string $thrown): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make(respondAnalyses([$thrown])));

    $document = generateDocument()->document->toArray();
    $response = resolveResponse($document, $document['paths'][$path][$method]['responses'][$status] ?? []);

    expect(array_keys($response['content'] ?? []))->toBe(['application/problem+json']);

    $schema = resolveSchema($document, $response['content']['application/problem+json']['schema'] ?? []);
    expect($schema['required'] ?? [])->toBe(['type', 'title', 'status'])
        ->and(array_keys($schema['properties'] ?? []))->toContain('type', 'title', 'status')
        ->and($schema['properties'] ?? [])->not->toHaveKey('message');

    // The helper adds `errors` for a validation failure and is read once for every thrown type, so the
    // member is one each body MAY carry and none must — vaguer than per status, and true of all four.
    expect($schema['properties'] ?? [])->toHaveKey('errors');
})->with([
    'a binding the route cannot resolve' => ['/api/forms/{form}', 'get', '404', ModelNotFoundException::class],
    'a request that fails validation' => ['/api/tickets', 'post', '422', ValidationException::class],
    'a guest on a guarded route' => ['/api/guarded-forms', 'get', '401', AuthenticationException::class],
    'a throttled request' => ['/api/rate-limited', 'get', '429', ThrottleRequestsException::class],
])->group('fixture');

it('keeps the framework body on a route the callback passes through', function (): void {
    setBuild('documents.default.routes.include', ['api/*', 'portal/*']);
    $analyses = respondAnalyses([ModelNotFoundException::class]);

    $document = emittedArray(localityBuild(static function (Router $router): void {
        $router->get('api/probe-forms/{form}', [FormController::class, 'show']);
        $router->get('portal/probe-forms/{form}', [FormController::class, 'show']);
    }, static fn (): TypeEngine => WorkbenchEngine::make($analyses)));

    $media = static fn (string $path): array => array_keys(resolveResponse($document, $document['paths'][$path]['get']['responses']['404'] ?? [])['content'] ?? []);

    expect($media('/api/probe-forms/{form}'))->toBe(['application/problem+json'])
        ->and($media('/portal/probe-forms/{form}'))->toBe(['application/json']);
})->group('fixture');

it('reads a branch on the exception against the class the handler hands the callback, not the one thrown', function (string $method, array $media): void {
    // A binding's missing model reaches `respond()` as a `NotFoundHttpException` (`ReceivedExceptionTest`).
    app()->instance(TypeEngine::class, WorkbenchEngine::make(respondAnalyses([ModelNotFoundException::class], $method)));

    $document = generateDocument()->document->toArray();
    $response = resolveResponse($document, $document['paths']['/api/forms/{form}']['get']['responses']['404'] ?? []);

    expect(array_keys($response['content'] ?? []))->toBe($media);
})->with([
    'a branch on the class it is handed' => ['notFoundReshaped', ['application/problem+json']],
    'a branch on the class thrown, never taken' => ['modelNotFoundReshaped', ['application/json']],
])->group('fixture');
