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
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\TokenController;

/**
 * The document an application gets for a refusal whose status is read off the reason's enum case: written at
 * the response, restated by a chain, and chosen at run time between cases that answer differently.
 * Byte-locked, warm as well as cold; the scripted returns are what the real engine recovers from the fixture
 * app's same three calls, which the last test holds them to.
 *
 * @return array<string, DType> action → the response it returns
 */
function caseStatusResponseReturns(): array
{
    $json = static fn (string $error, DType $status): ClassT => new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([new ArrayShapeField('error', new LiteralT($error))]),
        $status,
    ]);

    return [
        // Each case maps to one status, so that is the status sent.
        'expire' => $json('expired', new LiteralT(401)),
        'rotate' => $json('rotated', new LiteralT(409)),
        // Expired answers 401 and AlreadyRotated 409: no one code is true of every request, so none is read.
        'refuse' => new ClassT('Illuminate\\Http\\JsonResponse', [
            new ArrayShapeT([new ArrayShapeField('error', UnionT::of([new LiteralT('already_rotated'), new LiteralT('token_expired')]))]),
            ScalarT::int(),
        ]),
    ];
}

/** @return callable(): TypeEngine */
function caseStatusResponseEngine(): callable
{
    $analyses = [];
    foreach (caseStatusResponseReturns() as $action => $type) {
        $analyses[TokenController::class.'::'.$action] = new ActionAnalysis(returns: [new ReturnSite($type, new SourceLocation(''))]);
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function caseStatusResponseRoutes(): callable
{
    return static function (Router $router): void {
        $router->post('api/tokens/expire', [TokenController::class, 'expire']);
        $router->post('api/tokens/rotate', [TokenController::class, 'rotate']);
        $router->post('api/tokens/refuse', [TokenController::class, 'refuse']);
    };
}

it('publishes the status a refusal reads off its case, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(caseStatusResponseRoutes(), caseStatusResponseRoutes(), caseStatusResponseEngine());

    assertGolden('workbench-case-status-response.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (string $action, string $fixtureMethod): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $real = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/CaseStatusResponseController.php',
        'App\\Http\\Controllers\\CaseStatusResponseController',
        $fixtureMethod,
    ));

    expect(array_map(static fn (ReturnSite $site): DType => $site->type, $real->returns))
        ->toEqual([caseStatusResponseReturns()[$action]]);
})->with([
    'written at the response' => ['expire', 'json'],
    'restated by a chain' => ['rotate', 'earlyReturn'],
    'chosen at run time' => ['refuse', 'disagreeing'],
])->group('fixture');
