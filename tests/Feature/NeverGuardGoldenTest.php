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
use Workbench\App\Exceptions\ExportLockedException;
use Workbench\App\Http\Controllers\NeverGuardController;

/**
 * The document an application gets for a guard helper that refuses the request by throwing, called from an
 * action that otherwise answers 200: a trait method and an injected service's method returning `never`, two
 * returning `void` beside them, and a helper throwing a response for the framework to send. Byte-locked,
 * warm as well as cold; the scripted analyses are what the real engine recovers from the fixture app's same
 * calls, which the last test holds them to.
 *
 * @return array<string, ActionAnalysis>
 */
function neverGuardAnalyses(): array
{
    $at = static fn (DType $type): ReturnSite => new ReturnSite($type, new SourceLocation(''));
    $json = static fn (string $key, DType $value, int $status): ClassT => new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([new ArrayShapeField($key, $value)]),
        new LiteralT($status),
    ]);
    $thrown = static fn (string $class, int $status): ThrownException => new ThrownException($class, $status, [], ThrowConfidence::Certain, ThrowDisposition::Signal);

    $ok = $at($json('ok', new LiteralT(true), 200));
    $slowDown = $at($json('error', new LiteralT('slow down'), 429));

    $locked = static fn (): ThrownException => $thrown(ExportLockedException::class, 423);

    // A helper refusing the request answers it with what the helper throws, whether the helper returns
    // `never` or `void`: the framework runs the same `throw` either way, so each `never` route documents
    // what its `void` twin beside it does.
    return [
        'traitNeverHttp' => new ActionAnalysis(returns: [$ok], throws: [$thrown('Symfony\\Component\\HttpKernel\\Exception\\HttpException', 409)]),
        'traitNeverAbort' => new ActionAnalysis(returns: [$ok], throws: [$thrown('Symfony\\Component\\HttpKernel\\Exception\\HttpException', 404)]),
        'traitNeverCustom' => new ActionAnalysis(returns: [$ok], throws: [$locked()]),
        'traitNeverValidation' => new ActionAnalysis(returns: [$ok], throws: [$thrown('Illuminate\\Validation\\ValidationException', 422)]),
        // A response thrown for the framework to send is the response the request gets.
        'traitNeverCarried' => new ActionAnalysis(returns: [$ok, $slowDown]),
        'traitVoidCustom' => new ActionAnalysis(returns: [$ok], throws: [$locked()]),
        'traitVoidCarried' => new ActionAnalysis(returns: [$ok, $slowDown]),
        'serviceNeverCustom' => new ActionAnalysis(returns: [$ok], throws: [$locked()]),
        'privateNeverCarried' => new ActionAnalysis(returns: [$ok, $slowDown]),
        'staticNeverCarried' => new ActionAnalysis(returns: [$ok, $slowDown]),
    ];
}

/** @return callable(): TypeEngine */
function neverGuardEngine(): callable
{
    $analyses = [];
    foreach (neverGuardAnalyses() as $action => $analysis) {
        $analyses[NeverGuardController::class.'::'.$action] = $analysis;
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function neverGuardRoutes(): callable
{
    return static function (Router $router): void {
        foreach (array_keys(neverGuardAnalyses()) as $action) {
            $router->post('api/guarded/'.$action, [NeverGuardController::class, $action]);
        }
    };
}

it('publishes what a guard helper refuses with, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(neverGuardRoutes(), neverGuardRoutes(), neverGuardEngine());

    assertGolden('workbench-never-guard.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $actions = array_keys(neverGuardAnalyses());
    $real = FixtureRunner::analyzeMany(
        'app/Http/Controllers/NeverGuardController.php',
        'App\\Http\\Controllers\\NeverGuardController',
        $actions,
    );

    // What reaches the document: every return's type, and each throw it publishes. The fixture app's
    // exception is the workbench's under another namespace.
    $facts = static fn (ActionAnalysis $analysis): array => [
        array_map(static fn (ReturnSite $site): array => $site->type->toArray(), $analysis->returns),
        array_values(array_map(
            static fn (ThrownException $throw): string => str_replace('Workbench\\App\\', 'App\\', $throw->identityKey()).' '.$throw->confidence->value,
            array_filter($analysis->throws, static fn (ThrownException $throw): bool => $throw->disposition === ThrowDisposition::Signal),
        )),
    ];

    foreach (neverGuardAnalyses() as $action => $scripted) {
        /** @var array<string, mixed> $recovered */
        $recovered = $real[$action];

        expect($facts(ActionAnalysis::fromArray($recovered)))->toBe($facts($scripted), $action);
    }
})->group('fixture');
