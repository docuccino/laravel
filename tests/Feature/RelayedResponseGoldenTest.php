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
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Testing\ApiContract;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\RelayedResponseController;

/**
 * The document an application gets for a response it throws inside its own subclass of `HttpResponseException`:
 * one handing its parent the response it is given, one building that response itself, from a value it is handed
 * or not, two levels below the framework each way, and one built by its static factories. Byte-locked, warm as well as cold; the scripted
 * analyses are what the real engine recovers from the fixture app's same calls, which the last test holds
 * them to.
 *
 * @return array<string, ActionAnalysis> action → what the engine recovers from it
 */
function relayedResponseAnalyses(): array
{
    $at = static fn (DType $type): ReturnSite => new ReturnSite($type, new SourceLocation(''));
    $json = static fn (DType $body, DType $status): ClassT => new ClassT('Illuminate\\Http\\JsonResponse', [$body, $status]);
    $error = static fn (DType $value): ArrayShapeT => new ArrayShapeT([new ArrayShapeField('error', $value)]);
    $carried = static fn (string $error, int $status): ActionAnalysis => new ActionAnalysis(returns: [
        $at($json(new ArrayShapeT([new ArrayShapeField('error', new LiteralT($error))]), new LiteralT($status))),
    ]);

    return [
        // Laravel sends the response an HttpResponseException carries as it is, whichever class carries it: what
        // lands in the framework constructor's `$response` is each request's answer.
        'handedOn' => $carried('locked', 423),
        'handedOnNamed' => $carried('locked', 423),
        // Built in the constructor, typed where it is written: the reason it takes is any string.
        'builtItself' => new ActionAnalysis(returns: [$at($json($error(new ScalarT('string')), new LiteralT(423)))]),
        // Built from whichever case the constructor is handed: Gone answers 410 and Locked 423, so no one code
        // is true of every construction and none is read.
        'builtFromCase' => new ActionAnalysis(returns: [$at($json(
            $error(UnionT::of([new LiteralT('Gone'), new LiteralT('Locked')])),
            new UnknownT('status not folded'),
        ))]),
        'twoLevelsHandedOn' => $carried('unavailable', 503),
        'twoLevelsBuilt' => $carried('quota_exceeded', 429),
        // A static factory carries the response the `new` it makes is handed.
        'factory' => $carried('locked', 423),
        'staticFactory' => $carried('gone', 410),
    ];
}

/** @return callable(): TypeEngine */
function relayedResponseEngine(): callable
{
    $analyses = [];
    foreach (relayedResponseAnalyses() as $action => $analysis) {
        $analyses[RelayedResponseController::class.'::'.$action] = $analysis;
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function relayedResponseRoutes(): callable
{
    return static function (Router $router): void {
        foreach (array_keys(relayedResponseAnalyses()) as $action) {
            $router->post('api/relayed/'.$action, [RelayedResponseController::class, $action]);
        }
    };
}

it('publishes the response an application’s own response exception carries, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(relayedResponseRoutes(), relayedResponseRoutes(), relayedResponseEngine());

    assertGolden('workbench-relayed-response.uir.json', (new UirEmitter)->emit($warm->document));
});

it('documents the response the server really sends for each throw', function (string $action, int $status): void {
    $engine = relayedResponseEngine();
    relayedResponseRoutes()(app('router'));
    workbenchContract(bindEngine: static fn () => app()->instance(TypeEngine::class, $engine()));

    try {
        $response = $this->postJson('api/relayed/'.$action)->assertStatus($status);

        ApiContract::assertions()->assertValidResponse($response);
    } finally {
        @unlink(workbenchContractPath());
        ApiContract::reset();
    }
})->with([
    'a response handed on' => ['handedOn', 423],
    'a response handed on by name' => ['handedOnNamed', 423],
    'a response the class builds' => ['builtItself', 423],
    'a response the class builds from the case it is handed' => ['builtFromCase', 423],
    'a response handed on two levels below the framework' => ['twoLevelsHandedOn', 503],
    'a response built two levels below the framework' => ['twoLevelsBuilt', 429],
    'a static factory\'s response' => ['factory', 423],
    'a factory building `new static`' => ['staticFactory', 410],
]);

it('scripts what the real engine recovers from the fixture app’s same calls', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $actions = array_keys(relayedResponseAnalyses());
    $real = FixtureRunner::analyzeMany(
        'app/Http/Controllers/RelayedResponseController.php',
        'App\\Http\\Controllers\\RelayedResponseController',
        $actions,
    );

    // What reaches the document: every return's type, and each throw it publishes, under the class's name in
    // the application that threw it.
    $facts = static fn (ActionAnalysis $analysis, string $app): array => [
        array_map(static fn (ReturnSite $site): array => $site->type->toArray(), $analysis->returns),
        array_values(array_map(
            static fn (ThrownException $throw): string => str_replace($app, '', $throw->identityKey()),
            array_filter($analysis->throws, static fn (ThrownException $throw): bool => $throw->disposition === ThrowDisposition::Signal),
        )),
    ];

    foreach (relayedResponseAnalyses() as $action => $scripted) {
        /** @var array<string, mixed> $recovered */
        $recovered = $real[$action];

        expect($facts(ActionAnalysis::fromArray($recovered), 'App\\'))->toBe($facts($scripted, 'Workbench\\App\\'), $action);
    }
})->group('fixture');
