<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\SubscriptionController;

/**
 * The document an application gets for responses it derives from the framework's so that the class names
 * its status: `new GoneResponse` whose constructor hands `parent::__construct('', 410)`, a JSON response
 * with a status parameter defaulting to 503 and passed 429, a redirect fixed at a constant, a stream reading
 * the case it is handed, a file, Symfony's own response, a status set in the subclass's constructor, a
 * problem two classes below the framework, a fixed JSON body, a private constructor's factory, and one whose
 * status depends on a branch. Byte-locked, warm as well as cold; the scripted returns are what the real
 * engine recovers from the fixture app's same classes, which the last test holds them to.
 *
 * @return array<string, DType> action → the response it returns
 */
function responseSubclassReturns(): array
{
    $responses = 'Workbench\\App\\Http\\Responses\\';
    // A JSON or plain response is emitted as the framework's JSON response, its body, status and any media
    // type in the slots a `JsonResponse` carries them in; a file, stream or redirect keeps its own class.
    $json = static fn (DType $body, DType $status, DType ...$mediaType): ClassT => new ClassT('Illuminate\\Http\\JsonResponse', [$body, $status, ...$mediaType]);
    $stated = static fn (string $class, DType $status): ClassT => new ClassT($responses.$class, [new UnknownT('payload not folded'), $status]);
    $retry = new ArrayShapeT([new ArrayShapeField('retry', new LiteralT(true))]);

    return [
        'cancelled' => $json(new VoidT, new LiteralT(410)),
        'renew' => $json($retry, new LiteralT(503)),
        'throttled' => $json($retry, new LiteralT(429)),
        'moved' => $stated('MovedResponse', new LiteralT(301)),
        'invoices' => $stated('FaultStreamResponse', new LiteralT(410)),
        'ledger' => $stated('FaultStreamResponse', new UnknownT('status not folded')),
        'contract' => $stated('PartialFileResponse', new LiteralT(206)),
        'pause' => $json(new VoidT, new LiteralT(202)),
        'trial' => $json(new VoidT, new LiteralT(410)),
        'handle' => $json(new ArrayShapeT([
            new ArrayShapeField('title', new LiteralT('Conflict')),
            new ArrayShapeField('detail', ScalarT::string()),
        ]), new LiteralT(409), new LiteralT('application/problem+json')),
        'archived' => $json(new ArrayShapeT([new ArrayShapeField('message', new LiteralT('This resource is gone.'))]), new LiteralT(410)),
        'upgrade' => $json(new ArrayShapeT([new ArrayShapeField('error', new LiteralT('quota_exceeded'))]), new LiteralT(429)),
        'notes' => new ClassT($responses.'BranchingResponse'),
    ];
}

/** @return callable(): TypeEngine */
function responseSubclassEngine(): callable
{
    $analyses = [];
    foreach (responseSubclassReturns() as $action => $type) {
        $analyses[SubscriptionController::class.'::'.$action] = new ActionAnalysis(returns: [new ReturnSite($type, new SourceLocation(''))]);
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function responseSubclassRoutes(): callable
{
    return static function (Router $router): void {
        $router->get('api/subscriptions/cancelled', [SubscriptionController::class, 'cancelled']);
        $router->post('api/subscriptions/renew', [SubscriptionController::class, 'renew']);
        $router->post('api/subscriptions/throttled', [SubscriptionController::class, 'throttled']);
        $router->get('api/subscriptions/moved', [SubscriptionController::class, 'moved']);
        $router->get('api/subscriptions/invoices', [SubscriptionController::class, 'invoices']);
        $router->get('api/subscriptions/ledger', [SubscriptionController::class, 'ledger']);
        $router->get('api/subscriptions/contract', [SubscriptionController::class, 'contract']);
        $router->post('api/subscriptions/pause', [SubscriptionController::class, 'pause']);
        $router->get('api/subscriptions/trial', [SubscriptionController::class, 'trial']);
        $router->put('api/subscriptions/handle', [SubscriptionController::class, 'handle']);
        $router->get('api/subscriptions/archived', [SubscriptionController::class, 'archived']);
        $router->post('api/subscriptions/upgrade', [SubscriptionController::class, 'upgrade']);
        $router->get('api/subscriptions/notes', [SubscriptionController::class, 'notes']);
    };
}

it('publishes the status and body a derived response class hands the framework’s, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(responseSubclassRoutes(), responseSubclassRoutes(), responseSubclassEngine());

    assertGolden('workbench-response-subclass.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same classes', function (string $action, string $fixtureMethod): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $real = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/ResponseSubclassController.php',
        'App\\Http\\Controllers\\ResponseSubclassController',
        $fixtureMethod,
    ));

    // The fixture app's response classes are the workbench's under another namespace; JSON escapes the
    // separators it is named with.
    $named = static fn (DType $type): string => str_replace('Workbench\\\\App\\\\', 'App\\\\', json_encode($type->toArray(), JSON_THROW_ON_ERROR));

    expect(array_map(static fn (ReturnSite $site): string => $named($site->type), $real->returns))
        ->toBe([$named(responseSubclassReturns()[$action])]);
})->with([
    'new GoneResponse, handing its parent 410' => ['cancelled', 'gone'],
    'new RetryLaterResponse($data), its status defaulting to 503' => ['renew', 'retryLater'],
    'new RetryLaterResponse($data, 429)' => ['throttled', 'retryPassed'],
    'new MovedResponse($url), handing its parent a constant' => ['moved', 'moved'],
    'new FaultStreamResponse($cb, Case), reading $fault->…' => ['invoices', 'faulted'],
    'new FaultStreamResponse($cb, a choice of cases that disagree)' => ['ledger', 'faultChosen'],
    'new PartialFileResponse($file), handing its parent 206' => ['contract', 'partial'],
    'new AcceptedResponse, Symfony\'s response handed 202' => ['pause', 'accepted'],
    'new ExpiredResponse, setting 410 in its constructor' => ['trial', 'expired'],
    'new ConflictProblemResponse($detail), two classes below the framework' => ['handle', 'conflict'],
    'new GoneJsonResponse, handing its parent a fixed body' => ['archived', 'goneJson'],
    'QuotaResponse::exceeded(), its private constructor defaulting to 429' => ['upgrade', 'quota'],
    'new BranchingResponse, setting a status on one branch' => ['notes', 'branching'],
])->group('fixture');
