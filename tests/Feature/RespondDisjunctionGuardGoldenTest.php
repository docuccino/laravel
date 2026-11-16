<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallCondition;
use Docuccino\Core\Inference\ConditionTree;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\MapT;
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
 * The document an application gets when `$exceptions->respond()` passes through everything off its API, and
 * then in one guard everything that is not JSON or that an exception carries —
 * `if (! $response instanceof JsonResponse || $e instanceof HttpResponseException) { return $response; }` —
 * and rewrites the rest into problem+json. Byte-locked, warm as well as cold; the scripted analysis for each
 * thrown type is what the real engine recovers from the fixture app's own callback, which the last test
 * holds it to.
 *
 * @return array<class-string, ActionAnalysis>
 */
function respondDisjunctionGuardAnalyses(): array
{
    $problem = new ClassT(JsonResponse::class, [
        new ArrayShapeT([
            new ArrayShapeField('type', new LiteralT('about:blank')),
            new ArrayShapeField('title', new StatusTextMarkerT(ScalarT::string(), new LiteralT('Error'))),
            new ArrayShapeField('status', new StatusMarkerT),
            new ArrayShapeField('errors', new MapT(ScalarT::string(), new ListT(ScalarT::string())), optional: true),
        ]),
        new UnknownT('status not folded'),
        new LiteralT('application/problem+json'),
    ]);

    $api = static fn (bool $holds): CallCondition => new CallCondition('request', 'is', ['api/*'], $holds);
    $onApi = ConditionTree::leaf($api(true));
    $json = ConditionTree::leaf(new TypeCondition('response', JsonResponse::class, true));

    $offApi = new ReturnSite(new ClassT(Response::class), new SourceLocation(''), returnsParameter: 'response', conditions: [$api(false)], reachedWhen: ConditionTree::not($onApi));
    // The scope proves neither half of the guard around the pass-through, so only the guard as written says
    // what reaches it: for an exception that is not an HttpResponseException, a response that is not JSON.
    $passed = new ReturnSite(new ClassT(Response::class), new SourceLocation(''), returnsParameter: 'response', conditions: [$api(true)], reachedWhen: ConditionTree::all([ConditionTree::not($json), $onApi]));
    $rewritten = new ReturnSite($problem, new SourceLocation(''), conditions: [$api(true)], typeConditions: [
        new TypeCondition('response', JsonResponse::class, true),
        new TypeCondition('e', HttpResponseException::class, false),
    ], reachedWhen: ConditionTree::all([$onApi, $json]));

    return [
        ModelNotFoundException::class => new ActionAnalysis(returns: [$offApi, $passed, $rewritten]),
        ValidationException::class => new ActionAnalysis(returns: [$offApi, $passed, $rewritten]),
        // An HttpResponseException passes the guard whatever it carries, and the rewrite past it is not reached.
        HttpResponseException::class => new ActionAnalysis(returns: [$offApi, new ReturnSite(new ClassT(Response::class), new SourceLocation(''), returnsParameter: 'response', conditions: [$api(true)], reachedWhen: $onApi)]),
    ];
}

it('publishes the rewrite alone for every JSON error the one guard does not pass through, byte-identically', function (): void {
    setBuild('documents.default.routes.include', ['api/*']);

    $callback = static fn (Response $response, Throwable $e, Request $request): Response => $response;

    $callables = [];
    foreach (respondDisjunctionGuardAnalyses() as $thrown => $analysis) {
        $callables[registerRespondCallback($callback, $thrown)] = $analysis;
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

    assertGolden('workbench-respond-disjunction-guard.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s own callback', function (string $thrown): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $source = (string) file_get_contents(FixtureRunner::path('app/Exceptions/RespondCallbacks.php'));
    $line = 0;
    foreach (explode("\n", $source) as $index => $text) {
        if (str_contains($text, 'public function jsonOrHandoffGuarded(')) {
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

    expect($facts($real))->toBe($facts(respondDisjunctionGuardAnalyses()[$thrown]));
})->with([ModelNotFoundException::class, ValidationException::class, HttpResponseException::class])->group('fixture');
