<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\InvokableRenderer;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The inferred-handler tier wiring (design §6 flagship), stub-side: the mapper reflects the booted
 * handler's render callbacks, analyses the matching one (here scripted through the stub engine,
 * keyed by the CallableRef the mapper builds), and emits the handler's REAL status+shape — winning
 * the chain over the framework-default tier. A too-dynamic body defers to the next tier + a
 * diagnostic. (Narrowed-catch-all recovery is engine truth, proven by the --group=fixture
 * InferredHandlerTest in packages/inference-phpstan.)
 */
const MODEL_NOT_FOUND = ModelNotFoundException::class;

/**
 * Register a render callback on the booted handler and return the CallableRef symbol the mapper will
 * analyse it under (so the stub engine can be scripted for it).
 */
function registerRenderCallback(Closure $callback, string $exceptionType): string
{
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable($callback);

    $function = new ReflectionFunction($callback);

    return (new CallableRef(
        (string) $function->getFileName(),
        null,
        null,
        $function->getStartLine(),
        $function->getParameters()[0]->getName(),
        $exceptionType,
    ))->symbol();
}

/**
 * Register an INVOKABLE renderer (Laravel wraps it via `Closure::fromCallable()`) and return the
 * method-based CallableRef symbol the mapper will analyse it under — mirroring the mapper routing a
 * method-backed callback to method analysis rather than the by-line closure path.
 */
function registerInvokableRenderCallback(object $renderer, string $exceptionType): string
{
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable($renderer);

    $function = new ReflectionFunction(Closure::fromCallable($renderer));

    return (new CallableRef(
        (string) $function->getFileName(),
        $renderer::class,
        $function->getName(),
        0,
        $function->getParameters()[0]->getName(),
        $exceptionType,
    ))->symbol();
}

it('documents the handler’s real status + shape, winning over the framework tier', function (): void {
    $symbol = registerRenderCallback(
        static fn (ModelNotFoundException $e) => response()->json(['error' => 'gone', 'id' => 1], 410),
        MODEL_NOT_FOUND,
    );

    $engine = WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new ArrayShapeT([new ArrayShapeField('error', ScalarT::string()), new ArrayShapeField('id', ScalarT::int())]),
                new LiteralT(410),
            ]),
            new SourceLocation(''),
        )]),
    ]);
    app()->instance(TypeEngine::class, $engine);

    $responses = generateDocument()->document->toArray()['paths']['/api/forms/{form}']['get']['responses'];

    // The handler renders a 410 (its real status) — that wins; the framework 404 is not emitted.
    expect($responses)->toHaveKey('410')->and($responses)->not->toHaveKey('404');

    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['410']['x-docuccino']['provenance'] ?? []);
    expect($producers)->toContain('integration:inferred-handler')
        ->and($responses['410']['content']['application/json']['schema']['properties'] ?? [])->toHaveKeys(['error', 'id']);
});

it('defers to the framework tier + records a diagnostic when the body is too dynamic', function (): void {
    $symbol = registerRenderCallback(
        static fn (ModelNotFoundException $e) => response('not json'),
        MODEL_NOT_FOUND,
    );

    // The handler analysis recovers a plain Response (not a JsonResponse) — nothing to document.
    $engine = WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\Response'), new SourceLocation(''))]),
    ]);
    app()->instance(TypeEngine::class, $engine);

    $result = generateDocument();
    $responses = $result->document->toArray()['paths']['/api/forms/{form}']['get']['responses'];

    // Deferred: the framework-default 404 fills in instead.
    expect($responses)->toHaveKey('404');
    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['404']['x-docuccino']['provenance'] ?? []);
    expect($producers)->toContain('integration:framework-errors');

    $codes = array_map(static fn ($d): string => $d->code, $result->diagnostics);
    expect($codes)->toContain('inferred-handler.too-dynamic');
});

it('stays inert (framework tier owns the 404) when no handler matches the exception', function (): void {
    bindStubEngine();

    $responses = generateDocument()->document->toArray()['paths']['/api/forms/{form}']['get']['responses'];
    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['404']['x-docuccino']['provenance'] ?? []);

    expect($producers)->toContain('integration:framework-errors')
        ->and($producers)->not->toContain('integration:inferred-handler');
});

it('documents an invokable renderer via method analysis, winning over the framework tier', function (): void {
    // The common real-world shape: `$exceptions->render(new SomeRenderer)`. Laravel wraps it as a method-backed
    // closure, and the mapper must analyse `__invoke` (not the closure-by-line path, whose target line is
    // a method declaration, not a closure literal).
    $symbol = registerInvokableRenderCallback(new InvokableRenderer, MODEL_NOT_FOUND);

    $engine = WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new ArrayShapeT([new ArrayShapeField('error', ScalarT::string())]),
                new LiteralT(410),
            ]),
            new SourceLocation(''),
        )]),
    ]);
    app()->instance(TypeEngine::class, $engine);

    $responses = generateDocument()->document->toArray()['paths']['/api/forms/{form}']['get']['responses'];

    expect($responses)->toHaveKey('410')->and($responses)->not->toHaveKey('404');
    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['410']['x-docuccino']['provenance'] ?? []);
    expect($producers)->toContain('integration:inferred-handler')
        ->and($responses['410']['content']['application/json']['schema']['properties'] ?? [])->toHaveKey('error');
});

it('documents the recovered content type (application/problem+json) from a refined helper shape', function (): void {
    // The refiner recovers a JsonResponse<payload, status, contentType> — the adapter must document the
    // body under the recovered media type, not the default application/json.
    $symbol = registerRenderCallback(
        static fn (ModelNotFoundException $e) => response()->json(['type' => 'x', 'title' => 'y'], 404),
        MODEL_NOT_FOUND,
    );

    $engine = WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new ArrayShapeT([new ArrayShapeField('type', ScalarT::string()), new ArrayShapeField('title', ScalarT::string())]),
                new LiteralT(404),
                new LiteralT('application/problem+json'),
            ]),
            new SourceLocation(''),
        )]),
    ]);
    app()->instance(TypeEngine::class, $engine);

    $responses = generateDocument()->document->toArray()['paths']['/api/forms/{form}']['get']['responses'];

    expect($responses['404']['content'] ?? [])->toHaveKey('application/problem+json')
        ->and($responses['404']['content'])->not->toHaveKey('application/json')
        ->and($responses['404']['content']['application/problem+json']['schema']['properties'] ?? [])->toHaveKeys(['type', 'title']);
});

it('assembles a media-type example from folded literals and const-pins each member', function (): void {
    // The refiner folded the arm's per-arm literals into the body; the adapter must both const-pin each
    // member (schema) and surface them as a media-type example (Tom: the 403 shows status: 403 + the type const).
    $symbol = registerRenderCallback(
        static fn (ModelNotFoundException $e) => response()->json(['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403], 403),
        MODEL_NOT_FOUND,
    );

    $engine = WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new ArrayShapeT([
                    new ArrayShapeField('type', new LiteralT('about:blank')),
                    new ArrayShapeField('title', new LiteralT('Forbidden')),
                    new ArrayShapeField('status', new LiteralT(403)),
                ]),
                new LiteralT(403),
                new LiteralT('application/problem+json'),
            ]),
            new SourceLocation(''),
        )]),
    ]);
    app()->instance(TypeEngine::class, $engine);

    $media = generateDocument()->document->toArray()['paths']['/api/forms/{form}']['get']['responses']['403']['content']['application/problem+json'];

    expect($media['example'])->toBe(['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403])
        ->and($media['schema']['properties']['type']['const'])->toBe('about:blank')
        ->and($media['schema']['properties']['status']['const'])->toBe(403);
});

it('fills a status-provenance member with the response status, omits non-folding members, and is deterministic', function (): void {
    // A StatusMarkerT member echoes the response status; a widened `detail` did not fold. The example
    // must carry the concrete status and the folded type, and OMIT detail (never fabricated).
    $script = static fn (): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(
        new ClassT('Illuminate\\Http\\JsonResponse', [
            new ArrayShapeT([
                new ArrayShapeField('type', new LiteralT('about:blank')),
                new ArrayShapeField('detail', ScalarT::string()),
                new ArrayShapeField('status', new StatusMarkerT),
            ]),
            new LiteralT(403),
            new LiteralT('application/problem+json'),
        ]),
        new SourceLocation(''),
    )]);

    $build = function () use ($script): array {
        $symbol = registerRenderCallback(
            static fn (ModelNotFoundException $e) => response()->json(['type' => 'about:blank'], 403),
            MODEL_NOT_FOUND,
        );
        app()->instance(TypeEngine::class, WorkbenchEngine::make([$symbol => $script()]));

        return generateDocument()->document->toArray()['paths']['/api/forms/{form}']['get']['responses']['403']['content']['application/problem+json'];
    };

    $media = $build();

    expect($media['example'])->toBe(['type' => 'about:blank', 'status' => 403])
        ->and($media['schema']['properties']['status']['const'])->toBe(403)
        ->and($media['schema']['properties']['detail'])->not->toHaveKey('const');

    // Determinism is a product feature: a second build is byte-identical.
    expect(json_encode($build()['example']))->toBe(json_encode($media['example']));
});

it('emits no example for a non-shape (object-typed) body — nothing statically known to assemble', function (): void {
    // A handler that renders an object-typed body (not a keyed array literal) has no folded members, so
    // there is nothing to example: the schema is still documented, no example is fabricated.
    $symbol = registerRenderCallback(
        static fn (ModelNotFoundException $e) => response()->json(['ignored' => true], 403),
        MODEL_NOT_FOUND,
    );

    $engine = WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new ClassT('Workbench\\App\\Data\\FormData'),
                new LiteralT(403),
            ]),
            new SourceLocation(''),
        )]),
    ]);
    app()->instance(TypeEngine::class, $engine);

    $media = generateDocument()->document->toArray()['paths']['/api/forms/{form}']['get']['responses']['403']['content']['application/json'];

    expect($media)->toHaveKey('schema')->and($media)->not->toHaveKey('example');
});

it('falls back to the exception status hint when the recovered status did not fold', function (): void {
    // An enum-derived / dynamic status the refiner could not fold arrives as UnknownT; the adapter must
    // document the exception's own status classification (404 here) rather than guessing 200.
    $symbol = registerRenderCallback(
        static fn (ModelNotFoundException $e) => response()->json(['type' => 'x'], 404),
        MODEL_NOT_FOUND,
    );

    $engine = WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new ArrayShapeT([new ArrayShapeField('type', ScalarT::string())]),
                new UnknownT('status not folded'),
                new LiteralT('application/problem+json'),
            ]),
            new SourceLocation(''),
        )]),
    ]);
    app()->instance(TypeEngine::class, $engine);

    $responses = generateDocument()->document->toArray()['paths']['/api/forms/{form}']['get']['responses'];

    // Documented under the exception hint (404), not the 200 default; producer is the inferred tier.
    expect($responses)->toHaveKey('404');
    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['404']['x-docuccino']['provenance'] ?? []);
    expect($producers)->toContain('integration:inferred-handler')
        ->and($responses['404']['content']['application/problem+json']['schema']['properties'] ?? [])->toHaveKey('type');
});

it('defers SILENTLY (no too-dynamic diagnostic) when an arm delegates to the framework (null/void)', function (): void {
    // A `return null` / void arm is a framework delegation, not a fold failure — the tier must NOT raise
    // a too-dynamic deferral for it; the framework-default tier fills in.
    $symbol = registerRenderCallback(
        static fn (ModelNotFoundException $e) => null,
        MODEL_NOT_FOUND,
    );

    $engine = WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(new VoidT, new SourceLocation(''))]),
    ]);
    app()->instance(TypeEngine::class, $engine);

    $result = generateDocument();
    $responses = $result->document->toArray()['paths']['/api/forms/{form}']['get']['responses'];

    $codes = array_map(static fn ($d): string => $d->code, $result->diagnostics);
    expect($codes)->not->toContain('inferred-handler.too-dynamic');

    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['404']['x-docuccino']['provenance'] ?? []);
    expect($producers)->toContain('integration:framework-errors');
});

it('reports render-callback-skipped (never silently) for an unanalysable render callback', function (): void {
    bindStubEngine();

    // A first parameter with a builtin type is not an exception the tier can bind — unanalysable.
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable(static fn (string $whoops) => response()->json([], 400));

    $codes = array_map(static fn ($d): string => $d->code, generateDocument()->diagnostics);

    expect($codes)->toContain('inferred-handler.render-callback-skipped');
});
