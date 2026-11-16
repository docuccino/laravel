<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Exceptions\ExportFaultException;
use Workbench\App\Exceptions\ExportLockedException;
use Workbench\App\Http\Controllers\ThrowIfController;

/**
 * The document an application gets for a request refused through `throw_if()` or `throw_unless()`, in an
 * action that otherwise answers 200, beside guards handed a condition they never throw on. Byte-locked,
 * warm as well as cold; the scripted analyses are what the real engine recovers from the fixture app's same
 * calls, which the last test holds them to.
 *
 * @return array<string, ActionAnalysis>
 */
function throwIfAnalyses(): array
{
    $at = static fn (DType $type): ReturnSite => new ReturnSite($type, new SourceLocation(''));
    $ok = $at(new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([new ArrayShapeField('ok', new LiteralT(true))]),
        new LiteralT(200),
    ]));
    $thrown = static fn (string $class, ?int $status, ThrowConfidence $confidence = ThrowConfidence::Certain): ThrownException => new ThrownException($class, $status, [], $confidence, ThrowDisposition::Signal);
    $http = 'Symfony\\Component\\HttpKernel\\Exception\\HttpException';

    $locked = ExportLockedException::class;
    $fault = ExportFaultException::class;

    // Laravel throws what `throw_if()` is handed, builds a class it is named with the parameters after it,
    // and makes a `RuntimeException` of a message, so each refuses with the status that `throw` would carry.
    return [
        'ifInstance' => new ActionAnalysis(returns: [$ok], throws: [$thrown($http, 409)]),
        'unlessInstance' => new ActionAnalysis(returns: [$ok], throws: [$thrown($locked, 423)]),
        'ifClassString' => new ActionAnalysis(returns: [$ok], throws: [$thrown($locked, 423)]),
        'unlessClassString' => new ActionAnalysis(returns: [$ok], throws: [$thrown($http, 409)]),
        'ifNamedParameter' => new ActionAnalysis(returns: [$ok], throws: [$thrown($locked, 423)]),
        'ifMessage' => new ActionAnalysis(returns: [$ok], throws: [$thrown('RuntimeException', 500)]),
        'ifEnumInstance' => new ActionAnalysis(returns: [$ok], throws: [$thrown($fault, 423)]),
        'ifEnumClassString' => new ActionAnalysis(returns: [$ok], throws: [$thrown($fault, 410)]),
        'ifClosure' => new ActionAnalysis(returns: [$ok], throws: [$thrown($http, 410)]),
        // A response thrown for the framework to send is the response the request gets.
        'ifCarried' => new ActionAnalysis(returns: [$ok, $at(new ClassT('Illuminate\\Http\\JsonResponse', [
            new ArrayShapeT([new ArrayShapeField('error', new LiteralT('slow down'))]),
            new LiteralT(429),
        ]))]),
        // An error all the same, at a status chosen at run time.
        'ifChosen' => new ActionAnalysis(returns: [$ok], throws: [$thrown($http, null)]),
        'declaredGuard' => new ActionAnalysis(returns: [$ok], throws: [$thrown($http, 409, ThrowConfidence::Declared)]),
        // A guard handed the condition it never throws on refuses nothing.
        'ifFalse' => new ActionAnalysis(returns: [$ok]),
        'ifReadOnly' => new ActionAnalysis(returns: [$ok]),
        'abortIfFalse' => new ActionAnalysis(returns: [$ok]),
        'abortIfReadOnly' => new ActionAnalysis(returns: [$ok]),
        'reportIf' => new ActionAnalysis(returns: [$ok]),
    ];
}

/** @return callable(): TypeEngine */
function throwIfEngine(): callable
{
    $analyses = [];
    foreach (throwIfAnalyses() as $action => $analysis) {
        $analyses[ThrowIfController::class.'::'.$action] = $analysis;
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function throwIfRoutes(): callable
{
    return static function (Router $router): void {
        foreach (array_keys(throwIfAnalyses()) as $action) {
            $router->post('api/throw-if/'.$action, [ThrowIfController::class, $action]);
        }
    };
}

it('publishes what throw_if() and throw_unless() refuse with, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(throwIfRoutes(), throwIfRoutes(), throwIfEngine());

    assertGolden('workbench-throw-if.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $real = FixtureRunner::analyzeMany(
        'app/Http/Controllers/ThrowIfController.php',
        'App\\Http\\Controllers\\ThrowIfController',
        array_keys(throwIfAnalyses()),
    );

    // What reaches the document: every return's type, and each throw it publishes. The fixture app's
    // classes are the workbench's under another namespace.
    $facts = static fn (ActionAnalysis $analysis): array => [
        array_map(static fn (ReturnSite $site): array => $site->type->toArray(), $analysis->returns),
        array_values(array_map(
            static fn (ThrownException $throw): string => str_replace('Workbench\\App\\', 'App\\', $throw->identityKey()).' '.$throw->confidence->value,
            array_filter($analysis->throws, static fn (ThrownException $throw): bool => $throw->disposition === ThrowDisposition::Signal),
        )),
    ];

    foreach (throwIfAnalyses() as $action => $scripted) {
        /** @var array<string, mixed> $recovered */
        $recovered = $real[$action];

        expect($facts(ActionAnalysis::fromArray($recovered)))->toBe($facts($scripted), $action);
    }
})->group('fixture');
