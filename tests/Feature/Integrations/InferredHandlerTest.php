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
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\InvokableRenderer;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The inferred-handler tier wiring, stub-side: the mapper reflects the booted handler's render
 * callbacks, analyses the matching one (scripted through the stub engine, keyed by the CallableRef the
 * mapper builds) and emits the handler's own status + shape, winning the chain over the
 * framework-default tier. A too-dynamic body defers to the next tier with a diagnostic. The
 * narrowed-catch-all recovery half lives in inference-phpstan's `--group=fixture` InferredHandlerTest.
 */
const MODEL_NOT_FOUND = ModelNotFoundException::class;

/**
 * Register an invokable renderer (Laravel wraps it via `Closure::fromCallable()`) and return the
 * method-based CallableRef symbol the mapper will analyse it under — the mapper routes a method-backed
 * callback to method analysis rather than the by-line closure path.
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

    $document = generateDocument()->document->toArray();
    $responses = $document['paths']['/api/forms/{form}']['get']['responses'];

    // The handler renders a 410 (its real status) — that wins; the framework 404 is not emitted.
    expect($responses)->toHaveKey('410')->and($responses)->not->toHaveKey('404');

    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['410']['x-docuccino']['provenance'] ?? []);
    $schema = errorSchemaOf($document, '410', 'application/json');

    expect($producers)->toContain('integration:inferred-handler')
        ->and($schema)->toHaveKey('properties')
        ->and($schema['properties'])->toHaveKeys(['error', 'id']);
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
    // `$exceptions->render(new SomeRenderer)` is the common real-world shape. Laravel wraps it as a
    // method-backed closure, so the mapper has to analyse `__invoke` — the closure-by-line path would
    // land on a method declaration, not a closure literal.
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

    $document = generateDocument()->document->toArray();
    $responses = $document['paths']['/api/forms/{form}']['get']['responses'];

    expect($responses)->toHaveKey('410')->and($responses)->not->toHaveKey('404');
    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['410']['x-docuccino']['provenance'] ?? []);
    $schema = errorSchemaOf($document, '410', 'application/json');

    expect($producers)->toContain('integration:inferred-handler')
        ->and($schema)->toHaveKey('properties')
        ->and($schema['properties'])->toHaveKey('error');
});

it('documents the recovered content type (application/problem+json) from a refined helper shape', function (): void {
    // The refiner recovers a JsonResponse<payload, status, contentType>, so the body is documented under
    // the recovered media type rather than the default application/json.
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

    $document = generateDocument()->document->toArray();
    $content = resolveResponse($document, $document['paths']['/api/forms/{form}']['get']['responses']['404'])['content'] ?? [];

    $schema = errorSchemaOf($document, '404', 'application/problem+json');

    expect($content)->toHaveKey('application/problem+json')
        ->and($content)->not->toHaveKey('application/json')
        ->and($schema)->toHaveKey('properties')
        ->and($schema['properties'])->toHaveKeys(['type', 'title']);
});

it('assembles a media-type example from folded literals and const-pins each member', function (): void {
    // The refiner folded the arm's literals into the body, so the adapter both const-pins each member in
    // the schema and surfaces them as a media-type example.
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

    $document = generateDocument()->document->toArray();
    $media = mediaOf($document, '403', 'application/problem+json');
    $schema = errorSchemaOf($document, '403', 'application/problem+json');

    // The shape is shared; the example is the operation's own, which is exactly the split this hoist
    // exists to make.
    expect($media['example'])->toBe(['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403])
        ->and($schema)->toHaveKey('properties')
        ->and($schema['properties']['type']['const'])->toBe('about:blank')
        ->and($schema['properties']['status']['const'])->toBe(403);
});

it('fills a status-provenance member with the response status, completes the example, and is deterministic', function (): void {
    // A StatusMarkerT member echoes the response status; a widened `detail` didn't fold. `detail` is still
    // required, so it gets a type-derived placeholder rather than being left out — a partial example would
    // fail validation against the very schema it sits beside. The schema itself claims nothing extra.
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

        $document = generateDocument()->document->toArray();

        return [mediaOf($document, '403', 'application/problem+json'), errorSchemaOf($document, '403', 'application/problem+json')];
    };

    [$media, $schema] = $build();

    expect($media['example'])->toBe(['type' => 'about:blank', 'detail' => 'string', 'status' => 403])
        ->and($schema)->toHaveKey('properties')
        ->and($schema['properties']['status']['const'])->toBe(403)
        ->and($schema['properties']['detail'])->not->toHaveKey('const')
        ->and($schema['required'])->toBe(['type', 'detail', 'status']);

    // Determinism is a product feature: a second build is byte-identical.
    expect(json_encode($build()))->toBe(json_encode([$media, $schema]));
});

it('examples an object-typed body from the component its $ref points at', function (): void {
    // A handler rendering a Data object (not a keyed array literal) folds no members at all, so the example
    // is built from the hoisted component's own required properties. This is the shape that matters most in
    // practice: one shared error component, `$ref`'d, with a per-response example beside it so a viewer has
    // something to render.
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

    $media = mediaOf(generateDocument()->document->toArray(), '403', 'application/json');

    // The schema stays a bare $ref — the example is its sibling, so the shared component is reused as-is
    // rather than wrapped in an allOf that would make codegen emit a distinct type per status.
    $schema = $media['schema'];
    unset($schema['x-docuccino']);

    expect($schema)->toBe(['$ref' => '#/components/schemas/FormData'])
        ->and($media['example'])->toBe(['id' => 0, 'title' => 'string']);
});

it('falls back to the exception status hint when the recovered status did not fold', function (): void {
    // An enum-derived / dynamic status the refiner couldn't fold arrives as UnknownT, so the adapter
    // documents the exception's own status classification (404 here) rather than guessing 200.
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

    $document = generateDocument()->document->toArray();
    $responses = $document['paths']['/api/forms/{form}']['get']['responses'];

    // Documented under the exception hint (404), not the 200 default; producer is the inferred tier.
    expect($responses)->toHaveKey('404');
    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['404']['x-docuccino']['provenance'] ?? []);
    $schema = errorSchemaOf($document, '404', 'application/problem+json');

    expect($producers)->toContain('integration:inferred-handler')
        ->and($schema)->toHaveKey('properties')
        ->and($schema['properties'])->toHaveKey('type');
});

it('prefers the status the recovered body states over the exception hint', function (): void {
    // The hint is a classification of the exception TYPE, made without reading the renderer; a `status`
    // member the same render path folded to a literal is evidence from the render path itself. When the
    // response status didn't fold, the body wins — otherwise the response is filed under one status while
    // the body beside it states another, and the shared component is named for the wrong one.
    //
    // This is not exotic: a body rendered through a Data object's own `toResponse()` reads its status off
    // `$this->status`, which never folds, while the construction that built it folds `status:` fine.
    $symbol = registerRenderCallback(
        static fn (RuntimeException $e) => response()->json(['status' => 400], 400),
        RuntimeException::class,
    );

    $engine = WorkbenchEngine::make(
        [$symbol => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new ArrayShapeT([
                    new ArrayShapeField('type', new LiteralT('https://httpstatuses.io/400')),
                    new ArrayShapeField('title', ScalarT::string()),
                    new ArrayShapeField('status', new LiteralT(400)),
                ]),
                new UnknownT('status not folded'),
                new LiteralT('application/problem+json'),
            ]),
            new SourceLocation(''),
        )])],
        analysisOverrides: [
            // An exception outside the framework table: classified 500 by fallback, rendered as a 400.
            'Workbench\\App\\Http\\Controllers\\FormController::show' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Workbench\\App\\Data\\FormData'), new SourceLocation(''))],
                throws: [new ThrownException(RuntimeException::class, 500, [], ThrowConfidence::Certain, ThrowDisposition::Signal)],
            ),
        ],
    );
    app()->instance(TypeEngine::class, $engine);

    $document = generateDocument()->document->toArray();
    $responses = $document['paths']['/api/forms/{form}']['get']['responses'];

    expect($responses)->toHaveKey('400')->and($responses)->not->toHaveKey('500');

    $response = resolveResponse($document, $responses['400']);

    // The description is the reason phrase for the status actually documented, not the hint's.
    expect($response['description'] ?? null)->toBe('Bad Request')
        ->and($response['content']['application/problem+json']['example']['status'] ?? null)->toBe(400);
});

it('defers SILENTLY (no too-dynamic diagnostic) when an arm delegates to the framework (null/void)', function (): void {
    // A `return null` / void arm is a framework delegation, not a fold failure, so no too-dynamic
    // deferral — the framework-default tier just fills in.
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

it('joins the shared error body when the handler’s response came back with nothing in it', function (): void {
    // The shape a real renderer reaches whenever the analysis loses the body: `$r = Problem::make(…);
    // …; return $r;` used to hand the adapter a bare `JsonResponse` with no type args at all. Answering
    // that with a description and no `content` publishes "this error returns nothing" — a claim, and a
    // false one — and, being an answer, stops the chain before a tier that CAN state a body is asked.
    $symbol = registerRenderCallback(
        static fn (ModelNotFoundException $e) => response()->json(['dynamic' => true], 404),
        MODEL_NOT_FOUND,
    );

    $engine = WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse'),
            new SourceLocation(''),
        )]),
    ]);
    app()->instance(TypeEngine::class, $engine);

    $result = generateDocument();
    $document = $result->document->toArray();
    $response = $document['paths']['/api/forms/{form}']['get']['responses']['404'];

    // The 404 the two form routes share now REFERENCES the shared component rather than sitting inline
    // with a description and nothing else — the same body every other 404 in the document publishes.
    expect($response['$ref'] ?? null)->toBe('#/components/responses/NotFound');

    $schema = errorSchemaOf($document, '404', 'application/json');
    expect($schema['properties'] ?? null)->toBe(['message' => ['type' => 'string']])
        ->and($schema['required'] ?? null)->toBe(['message']);

    // Deferring is not going quiet: the author is told which callback lost the shape.
    $codes = array_map(static fn ($d): string => $d->code, $result->diagnostics);
    expect($codes)->toContain('inferred-handler.too-dynamic');
});

/**
 * The whole decline rule, arm by arm. The tier answers when it has something the tiers behind it do not
 * — the body, a status it folded itself, or a status HTTP forbids a body on — and declines when it has
 * nothing, since a bodyless error response is a false claim and an answer that ends the chain.
 *
 * `$typeArgs` is what the engine recovered; `$status` is where the response lands and `$producer` which
 * tier owns it. The 404 is the hint the thrown `ModelNotFoundException` arrives with.
 */
it('answers only with what the tiers behind it do not have', function (array $typeArgs, string $status, string $producer, bool $body): void {
    $symbol = registerRenderCallback(
        static fn (ModelNotFoundException $e) => response()->json(['dynamic' => true], 404),
        MODEL_NOT_FOUND,
    );

    $engine = WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', $typeArgs),
            new SourceLocation(''),
        )]),
    ]);
    app()->instance(TypeEngine::class, $engine);

    $document = generateDocument()->document->toArray();
    $responses = $document['paths']['/api/forms/{form}']['get']['responses'];

    expect($responses)->toHaveKey($status);

    $producers = array_map(static fn (array $r): string => $r['producer'], $responses[$status]['x-docuccino']['provenance'] ?? []);
    expect($producers)->toContain($producer)
        ->and(isset(resolveResponse($document, $responses[$status])['content']))->toBe($body);
})->with([
    // Nothing recovered at all — the refiner declined, so there is no shape and no status of its own.
    'a bare JsonResponse' => [[], '404', 'integration:framework-errors', true],
    // A payload that did not fold, and a status borrowed from the throw: still nothing the fallback lacks.
    'an unfolded payload under the hint' => [
        [new UnknownT('payload not folded'), new UnknownT('status not folded')],
        '404',
        'integration:framework-errors',
        true,
    ],
    // A status the render path folded is a fact no later tier has — they classify the exception type
    // without reading the renderer — so the response keeps it and says only what it knows.
    'an unfolded payload under a folded status' => [
        [new UnknownT('payload not folded'), new LiteralT(409)],
        '409',
        'integration:inferred-handler',
        false,
    ],
    // A status HTTP forbids a body on: no content is the truth there, not a loss.
    'an unfolded payload under a bodyless status' => [
        [new UnknownT('payload not folded'), new LiteralT(204)],
        '204',
        'integration:inferred-handler',
        false,
    ],
    // A body it can state, which is the tier doing its job.
    'a folded payload' => [
        [new ArrayShapeT([new ArrayShapeField('gone', ScalarT::string())]), new LiteralT(410)],
        '410',
        'integration:inferred-handler',
        true,
    ],
]);
