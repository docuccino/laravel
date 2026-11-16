<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Exceptions\ExportFaultException;
use Workbench\App\Http\Controllers\ExportController;

/**
 * The document an application gets for a refusal whose enum case is looked up with `from()`: from a value
 * written in the code, from one no case is backed by, and from one the request chooses. Byte-locked, warm as
 * well as cold; the scripted throws are what the real engine recovers from the fixture app's same calls,
 * which the last test holds them to.
 *
 * @return array<string, list<ThrownException>> action → its throws
 */
function enumLookupThrows(): array
{
    $thrown = static fn (string $class, ?int $status, ThrowConfidence $confidence): ThrownException => new ThrownException($class, $status, [], $confidence, ThrowDisposition::Signal);

    return [
        // 423 backs a case, so `from()` cannot throw the TypeError or the ValueError it declares.
        'lock' => [
            $thrown(ExportFaultException::class, 423, ThrowConfidence::Certain),
        ],
        // 999 backs no case, so `from()` always throws its ValueError and the refusal is never built.
        'retire' => [
            $thrown(ValueError::class, 500, ThrowConfidence::Certain),
        ],
        // The same lookup read for the message abort() is handed: abort() is never called, so no 404.
        'withdraw' => [
            $thrown(ValueError::class, 500, ThrowConfidence::Certain),
        ],
        // A value the request chooses is not matched, so both stand as declared.
        'fail' => [
            $thrown(ExportFaultException::class, null, ThrowConfidence::Certain),
            $thrown(TypeError::class, 500, ThrowConfidence::Declared),
            $thrown(ValueError::class, 500, ThrowConfidence::Declared),
        ],
    ];
}

/** @return callable(): TypeEngine */
function enumLookupEngine(): callable
{
    // Both actions always throw, so neither returns anything.
    $analyses = [];
    foreach (enumLookupThrows() as $action => $throws) {
        $analyses[ExportController::class.'::'.$action] = new ActionAnalysis(throws: $throws);
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function enumLookupRoutes(): callable
{
    return static function (Router $router): void {
        $router->post('api/exports/{export}/lock', [ExportController::class, 'lock']);
        $router->post('api/exports/{export}/retire', [ExportController::class, 'retire']);
        $router->post('api/exports/{export}/withdraw', [ExportController::class, 'withdraw']);
        $router->post('api/exports/{export}/fail/{code}', [ExportController::class, 'fail']);
    };
}

it('publishes what a looked-up case can throw, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(enumLookupRoutes(), enumLookupRoutes(), enumLookupEngine());

    assertGolden('workbench-enum-lookup.uir.json', (new UirEmitter)->emit($warm->document));
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (string $action, string $fixtureMethod): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $real = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/ThrowsController.php',
        'App\\Http\\Controllers\\ThrowsController',
        $fixtureMethod,
    ));

    // The class, status, confidence and disposition of each throw; the fixture app's exception is the
    // workbench's under another namespace, and where each is thrown is the fixture's own file.
    $facts = static function (array $throws): array {
        $out = array_map(static fn (ThrownException $throw): string => implode(' ', [
            str_replace('Workbench\\App\\', 'App\\', $throw->exceptionFqcn),
            $throw->httpStatusHint ?? 'null',
            $throw->confidence->value,
            $throw->disposition->value,
        ]), $throws);
        sort($out);

        return $out;
    };

    expect($real->returns)->toBe([])
        ->and($facts($real->throws))->toBe($facts(enumLookupThrows()[$action]));
})->with([
    'a value written in the code' => ['lock', 'enumFromStatus'],
    'a value no case is backed by' => ['retire', 'enumFromNoCase'],
    'the same, read for a message' => ['withdraw', 'enumFromNoCaseMessage'],
    'a value the request chooses' => ['fail', 'enumFromRuntime'],
])->group('fixture');
