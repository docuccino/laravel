<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Exceptions\UnsupportedRadiusException;
use Workbench\App\Http\Controllers\AssertionGuardController;

/**
 * The document an application gets for throws with no status that assert what it already guarantees,
 * beside the same throws behind a test of a value and exceptions the framework or the application renders
 * at a status. An assertion is no response a request receives, so it is published only at a status the
 * application renders it with. Byte-locked, warm as well as cold; the scripted analyses are what the real
 * engine recovers from the fixture app's same actions, which a test below holds them to.
 *
 * @return array<string, ActionAnalysis>
 */
function assertionGuardAnalyses(): array
{
    $ok = new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([new ArrayShapeField('ok', new LiteralT(true))]),
        new LiteralT(200),
    ]), new SourceLocation(''));
    $thrown = static fn (string $class, ThrowDisposition $disposition): ThrownException => new ThrownException($class, 500, [], ThrowConfidence::Certain, $disposition);
    $asserted = static fn (string $class): ThrownException => $thrown($class, ThrowDisposition::Assertion);
    $signal = static fn (string $class): ThrownException => $thrown($class, ThrowDisposition::Signal);

    return [
        'listWithNeverHelper' => new ActionAnalysis(returns: [$ok], throws: [$asserted('LogicException')]),
        'unlessUser' => new ActionAnalysis(returns: [$ok], throws: [$asserted('RuntimeException')]),
        'unlessUserAuthenticated' => new ActionAnalysis(returns: [$ok], throws: [$asserted('Illuminate\\Auth\\AuthenticationException')]),
        'ifSystemAuthenticated' => new ActionAnalysis(returns: [$ok], throws: [$signal('Illuminate\\Auth\\AuthenticationException')]),
        'radiusOutOfRange' => new ActionAnalysis(returns: [$ok], throws: [$asserted(UnsupportedRadiusException::class)]),
        'valueGuard' => new ActionAnalysis(returns: [$ok], throws: [$signal('RuntimeException')]),
        'sameClassTwoSites' => new ActionAnalysis(returns: [$ok], throws: [$signal('RuntimeException')]),
        'ignoredAssertion' => new ActionAnalysis(returns: [$ok], throws: [$asserted('LogicException')]),
    ];
}

/** @return callable(): TypeEngine */
function assertionGuardEngine(): callable
{
    $analyses = [];
    foreach (assertionGuardAnalyses() as $action => $analysis) {
        $analyses[AssertionGuardController::class.'::'.$action] = $analysis;
    }

    // The application's render() as the engine recovers it.
    $render = new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([new ArrayShapeField('message', ScalarT::string())]),
        new LiteralT(422),
    ]), new SourceLocation(''))]);

    return static fn (): TypeEngine => WorkbenchEngine::make(
        callables: [UnsupportedRadiusException::class.'::render' => $render],
        analysisOverrides: $analyses,
    );
}

function assertionGuardRoutes(): callable
{
    return static function (Router $router): void {
        foreach (array_keys(assertionGuardAnalyses()) as $action) {
            $router->post('api/asserted/'.$action, [AssertionGuardController::class, $action]);
        }
    };
}

it('publishes no 500 for an assertion, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(assertionGuardRoutes(), assertionGuardRoutes(), assertionGuardEngine());

    assertGolden('workbench-assertion-guard.uir.json', (new UirEmitter)->emit($warm->document));
});

it('publishes an assertion only at a status the application renders it with', function (string $action, array $statuses): void {
    /** @var array{paths: array<string, array{post: array{responses: array<string, mixed>}}>} $document */
    $document = emittedArray(localityBuild(assertionGuardRoutes(), assertionGuardEngine()));

    expect(array_map('strval', array_keys($document['paths']['/api/asserted/'.$action]['post']['responses'])))->toBe($statuses);
})->with([
    // Reached only where the application's own guarantees fail, so no request gets the 500 it falls back to.
    'a LogicException from a never helper' => ['listWithNeverHelper', ['200']],
    'a RuntimeException behind an instanceof' => ['unlessUser', ['200']],
    // The framework renders this class at 401 and the application its own at 422: a status of their own is a
    // response the request gets, whatever guard it sits behind.
    'an AuthenticationException behind an instanceof' => ['unlessUserAuthenticated', ['200', '401']],
    'an InvalidArgumentException the application renders' => ['radiusOutOfRange', ['200', '422']],
    // Not an assertion at all.
    'an AuthenticationException behind a flag' => ['ifSystemAuthenticated', ['200', '401']],
    'a RuntimeException behind a test of a value' => ['valueGuard', ['200', '500']],
    'a class one site asserts and another refuses with' => ['sameClassTwoSites', ['200', '500']],
]);

it('says an ignore written for an assertion\'s 500 now drops nothing', function (): void {
    $build = localityBuild(assertionGuardRoutes(), assertionGuardEngine());
    /** @var array{paths: array<string, array{post: array{responses: array<string, mixed>}}>, components?: array{responses?: array<string, mixed>}} $document */
    $document = emittedArray($build);

    // The build drops the assertion itself, so the declaration did nothing, which is worth saying: it can go.
    $codes = array_map(static fn (Diagnostic $d): string => $d->code, array_values(array_filter(
        $build->diagnostics,
        static fn (Diagnostic $d): bool => str_contains((string) $d->routeSignature, 'api/asserted/ignoredAssertion'),
    )));

    expect(array_map('strval', array_keys($document['paths']['/api/asserted/ignoredAssertion']['post']['responses'])))->toBe(['200'])
        ->and($codes)->toBe(['attribute.ignore-response-unmatched']);
});

it('scripts what the real engine recovers from the fixture app’s same actions', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());

    // `ignoredAssertion` is `listWithNeverHelper` with an ignore on it, which the engine never reads.
    $scripted = assertionGuardAnalyses();
    unset($scripted['ignoredAssertion']);

    $real = FixtureRunner::analyzeMany(
        'app/Http/Controllers/AssertionGuardController.php',
        'App\\Http\\Controllers\\AssertionGuardController',
        array_keys($scripted),
    );

    // What reaches the document: each throw handed on, with what it is for. The fixture app's exception is the
    // workbench's under another namespace.
    $facts = static fn (ActionAnalysis $analysis): array => array_values(array_map(
        static fn (ThrownException $throw): string => str_replace('Workbench\\App\\', 'App\\', $throw->identityKey()).' '.$throw->disposition->value,
        array_filter($analysis->throws, static fn (ThrownException $throw): bool => $throw->disposition === ThrowDisposition::Signal || $throw->disposition === ThrowDisposition::Assertion),
    ));

    foreach ($scripted as $action => $analysis) {
        /** @var array<string, mixed> $recovered */
        $recovered = $real[$action];

        expect($facts(ActionAnalysis::fromArray($recovered)))->toBe($facts($analysis), $action);
    }
})->group('fixture');
