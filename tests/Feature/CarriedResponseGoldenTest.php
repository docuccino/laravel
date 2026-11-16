<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\CarriedResponseController;

/**
 * The document an application gets for a response it hands the framework inside an exception: an
 * `HttpResponseException` carrying a response built each way one is, thrown at the action and from a helper,
 * and a response handed to `abort()`, which throws one. Byte-locked, warm as well as cold; the scripted
 * analyses are what the real engine recovers from the fixture app's same calls, which the last test holds
 * them to.
 *
 * @return array<string, ActionAnalysis> action → what the engine recovers from it
 */
function carriedResponseAnalyses(): array
{
    $at = static fn (DType $type): ReturnSite => new ReturnSite($type, new SourceLocation(''));
    $json = static fn (DType $body, DType $status): ClassT => new ClassT('Illuminate\\Http\\JsonResponse', [$body, $status]);
    $error = static fn (DType $value): ArrayShapeT => new ArrayShapeT([new ArrayShapeField('error', $value)]);

    $locked = $at($json($error(new LiteralT('locked')), new LiteralT(423)));
    $ok = $at($json(new ArrayShapeT([new ArrayShapeField('ok', new LiteralT(true))]), new LiteralT(200)));

    return [
        // The framework sends the response an HttpResponseException carries as it is, so each is the
        // response the request is answered with, however it was built: its status, body and media type.
        'helperJson' => new ActionAnalysis(returns: [$locked]),
        'facadeJson' => new ActionAnalysis(returns: [$locked]),
        'newJson' => new ActionAnalysis(returns: [$locked]),
        'text' => new ActionAnalysis(returns: [$at(new ClassT('Illuminate\\Http\\JsonResponse', [
            new LiteralT('Already submitted.'),
            new LiteralT(409),
            new LiteralT('text/html'),
        ]))]),
        'redirect' => new ActionAnalysis(returns: [$at(new ClassT('Illuminate\\Http\\RedirectResponse'))]),
        'subclass' => new ActionAnalysis(returns: [$at($json(
            new ArrayShapeT([new ArrayShapeField('message', new LiteralT('This resource is gone.'))]),
            new LiteralT(410),
        ))]),
        // Thrown from a helper, beside the response the action returns when it is not.
        'viaHelper' => new ActionAnalysis(returns: [$ok, $locked]),
        // A helper handed the case builds the carried response, and that case's value is its status.
        'caseHelper' => new ActionAnalysis(returns: [$at($json($error(new LiteralT('Locked')), new LiteralT(423)))]),
        // Gone answers 410 and Locked 423: no one code is true of every request, so none is read.
        'caseChosen' => new ActionAnalysis(returns: [$at($json(
            $error(UnionT::of([new LiteralT('Gone'), new LiteralT('Locked')])),
            new UnknownT('status not folded'),
        ))]),
        // `abort()` handed a response throws an HttpResponseException carrying it.
        'abortJson' => new ActionAnalysis(returns: [$locked]),
        'abortFacade' => new ActionAnalysis(returns: [$locked]),
        'abortIf' => new ActionAnalysis(returns: [$ok, $locked]),
    ];
}

/** @return callable(): TypeEngine */
function carriedResponseEngine(): callable
{
    $analyses = [];
    foreach (carriedResponseAnalyses() as $action => $analysis) {
        $analyses[CarriedResponseController::class.'::'.$action] = $analysis;
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function carriedResponseRoutes(): callable
{
    return static function (Router $router): void {
        foreach (array_keys(carriedResponseAnalyses()) as $action) {
            $router->post('api/carried/'.$action, [CarriedResponseController::class, $action]);
        }
    };
}

it('publishes the response an exception carries, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(carriedResponseRoutes(), carriedResponseRoutes(), carriedResponseEngine());

    assertGolden('workbench-carried-response.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $actions = array_keys(carriedResponseAnalyses());
    $real = FixtureRunner::analyzeMany(
        'app/Http/Controllers/CarriedResponseController.php',
        'App\\Http\\Controllers\\CarriedResponseController',
        $actions,
    );

    // What reaches the document: every return's type, and each throw it publishes.
    $facts = static fn (ActionAnalysis $analysis): array => [
        array_map(static fn (ReturnSite $site): array => $site->type->toArray(), $analysis->returns),
        array_values(array_map(
            static fn (ThrownException $throw): string => $throw->identityKey(),
            array_filter($analysis->throws, static fn (ThrownException $throw): bool => $throw->disposition === ThrowDisposition::Signal),
        )),
    ];

    foreach (carriedResponseAnalyses() as $action => $scripted) {
        /** @var array<string, mixed> $recovered */
        $recovered = $real[$action];

        expect($facts(ActionAnalysis::fromArray($recovered)))->toBe($facts($scripted), $action);
    }
})->group('fixture');
