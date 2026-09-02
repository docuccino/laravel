<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
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
use Docuccino\Laravel\Tests\Fixtures\InferredHandler\ProbeRejection;
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

    // Deferred: the framework-default tier takes the 404 — and, seeing a renderer it could not read,
    // publishes the status it classifies without a body it would be inventing.
    expect($responses)->toHaveKey('404');
    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['404']['x-docuccino']['provenance'] ?? []);
    expect($producers)->toContain('integration:framework-errors')
        ->and(resolveResponse($result->document->toArray(), $responses['404']))->not->toHaveKey('content');

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

    // And the framework's own body is published in full: a renderer that hands the throwable back has
    // refuted nothing, so nothing stands the framework tier down.
    $schema = errorSchemaOf($result->document->toArray(), '404', 'application/json');
    expect($schema['properties'] ?? null)->toBe(['message' => ['type' => 'string']]);
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

it('leaves the body unsaid where a renderer it could not read has already replaced the framework’s', function (): void {
    // The shape a real renderer reaches whenever the analysis loses the body: `$r = Problem::make(…);
    // …; return $r;` hands the adapter a bare `JsonResponse` with no type args at all. The renderer still
    // demonstrably answers for this exception, so the framework's `{message}` is a shape the server does
    // not send — publishing it puts a second error vocabulary in the document and a wrong type in every
    // generated client. The status is classification the framework does own, so it stands.
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
    $response = resolveResponse($document, $document['paths']['/api/forms/{form}']['get']['responses']['404']);

    expect($response['description'] ?? null)->toBe('Not Found')
        ->and($response)->not->toHaveKey('content')
        // …and nothing was minted for a body nobody published: no `NotFound` schema, no shared response.
        ->and($document['components']['schemas'] ?? [])->not->toHaveKey('NotFound')
        ->and($document['components']['responses'] ?? [])->not->toHaveKey('NotFound');

    $producers = array_map(static fn (array $r): string => $r['producer'], $document['paths']['/api/forms/{form}']['get']['responses']['404']['x-docuccino']['provenance'] ?? []);
    expect($producers)->toContain('integration:framework-errors');

    // Standing aside is not going quiet: the author is told which callback lost the shape.
    $codes = array_map(static fn ($d): string => $d->code, $result->diagnostics);
    expect($codes)->toContain('inferred-handler.too-dynamic');
});

it('states the representation and the loss where it folded a status and no body', function (): void {
    // The population the row above lands on, in full. This tier ANSWERS here — it folded a 409 no later
    // tier could have — and answering ends the chain, so whatever it publishes is the whole of what the
    // document will ever say about that error. Two things follow, and each is a rule this package already
    // states elsewhere: a response with no `content` says the error returns NOTHING, which is false of a
    // renderer that always sends a body, so the representation a `JsonResponse` sends is published under
    // an empty schema; and a partial recovery that says nothing is a silent degradation, so the callback
    // whose shape was lost is named where the author will see it.
    $symbol = registerRenderCallback(
        static fn (ModelNotFoundException $e) => response()->json(['dynamic' => true], 409),
        MODEL_NOT_FOUND,
    );

    app()->instance(TypeEngine::class, WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [new UnknownT('payload not folded'), new LiteralT(409)]),
            new SourceLocation(''),
        )]),
    ]));

    $result = generateDocument();
    $document = $result->document->toArray();
    $response = resolveResponse($document, $document['paths']['/api/forms/{form}']['get']['responses']['409']);
    $codes = array_map(static fn ($d): string => $d->code, $result->diagnostics);

    expect(array_keys($response['content'] ?? []))->toBe(['application/json'])
        // Empty, not `{type: object}`: the payload may be a list or a scalar, and nothing read it. An
        // empty schema hoists nowhere either, so no component is minted for a shape nobody saw.
        ->and($response['content']['application/json']['schema'] ?? null)->toBe([])
        ->and($codes)->toContain('inferred-handler.too-dynamic');
});

/**
 * The whole decline rule, arm by arm. The tier answers when it has something the tiers behind it do not
 * — the body, the media type it is sent as, a status it folded itself, or a status HTTP forbids a body
 * on — and declines when it has nothing, since a bodyless error response is a false claim and an answer
 * that ends the chain.
 *
 * Which is also the rule for what it answers WITH: wherever it answers and the payload did not fold, it
 * publishes the media type under an empty schema, because a response with no `content` says the error
 * returns nothing and there is no tier behind it left to say otherwise. The one exception is the status
 * HTTP forbids a body on, where nothing IS the truth.
 *
 * `$typeArgs` is what the engine recovered; `$status` is where the response lands and `$producer` which
 * tier owns it. The 404 is the hint the thrown `ModelNotFoundException` arrives with. `$body` is what the
 * document ends up carrying rather than what this tier wrote: where it declines, the framework tier has
 * already seen a renderer it could not read and states the status without a body.
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
    // Nothing recovered at all — the refiner declined, so there is no shape and no status of its own. The
    // framework tier takes the 404 and, seeing a renderer it could not read, says only that much.
    'a bare JsonResponse' => [[], '404', 'integration:framework-errors', false],
    // A payload that did not fold, and a status borrowed from the throw: still nothing the fallback lacks.
    'an unfolded payload under the hint' => [
        [new UnknownT('payload not folded'), new UnknownT('status not folded')],
        '404',
        'integration:framework-errors',
        false,
    ],
    // A status the render path folded is a fact no later tier has — they classify the exception type
    // without reading the renderer — so the response keeps it. The body it carries is `application/json`
    // under an empty schema: a `JsonResponse` sends that type, and this tier ANSWERING means no tier
    // behind it will be asked, so publishing no `content` would be the document stating that a 409 the
    // renderer really gives a body to returns nothing at all.
    'an unfolded payload under a folded status' => [
        [new UnknownT('payload not folded'), new LiteralT(409)],
        '409',
        'integration:inferred-handler',
        true,
    ],
    // A media type the render path folded is a fact no later tier has either, and the body it carries is
    // one the server really sends — so the response states the representation and constrains nothing.
    'an unfolded payload under a folded media type' => [
        [new UnknownT('payload not folded'), new UnknownT('status not folded'), new LiteralT('application/problem+json')],
        '404',
        'integration:inferred-handler',
        true,
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

/**
 * A renderer whose body folded under a status nothing could read, for a throw that carried none either —
 * an `HttpException` subclass whose own status the analyser cannot read arrives exactly so, and so does a
 * `$e->getCode()` status on a plain exception.
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function unreadStatusBuild(): array
{
    $symbol = registerRenderCallback(
        static fn (ProbeRejection $e) => response()->json(['type' => 'about:blank', 'title' => 'Nope'], $e->getCode(), ['Content-Type' => 'application/problem+json']),
        ProbeRejection::class,
    );

    $engine = WorkbenchEngine::make(
        [$symbol => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new ArrayShapeT([
                    new ArrayShapeField('type', new LiteralT('about:blank')),
                    new ArrayShapeField('title', ScalarT::string()),
                ]),
                new UnknownT('status not folded'),
                new LiteralT('application/problem+json'),
            ]),
            new SourceLocation(''),
        )])],
        analysisOverrides: [
            'Workbench\\App\\Http\\Controllers\\FormController::show' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Workbench\\App\\Data\\FormData'), new SourceLocation(''))],
                throws: [new ThrownException(ProbeRejection::class, null, [], ThrowConfidence::Certain, ThrowDisposition::Signal)],
            ),
        ],
    );
    app()->instance(TypeEngine::class, $engine);
    $result = generateDocument();
    $document = $result->document->toArray();

    return [$document, ['responses' => $document['paths']['/api/forms/{form}']['get']['responses'], 'diagnostics' => $result->diagnostics]];
}

it('keeps the body it folded, under the classification, when nothing read a status', function (): void {
    // The regression: only the STATUS was unreadable, and declining threw away two proven facts — the
    // shape and the media type the renderer sends them as — to avoid stating one unproven number. The
    // response then fell to a tier that asserts `application/json` `{message}`, so one application
    // published two error vocabularies and every generated client got the wrong type for this error.
    [$document, $build] = unreadStatusBuild();
    $responses = $build['responses'];

    // Filed under the exception's framework classification — the same key the tiers behind it would have
    // used, so the error is published once — and never under 200, which would merge it into the success.
    expect($responses)->toHaveKey('500');
    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['500']['x-docuccino']['provenance'] ?? []);
    expect($producers)->toContain('integration:inferred-handler')
        ->and(array_map(static fn (array $r): string => $r['producer'], $responses['200']['x-docuccino']['provenance'] ?? []))
        ->not->toContain('integration:inferred-handler');

    $response = resolveResponse($document, $responses['500']);
    $content = $response['content'] ?? [];

    // The media type the renderer set, and the shape it folded — not `application/json` `{message}`.
    expect($content)->toHaveKey('application/problem+json')
        ->and($content)->not->toHaveKey('application/json');

    $schema = resolveSchema($document, $content['application/problem+json']['schema'] ?? []);
    expect($schema['properties'] ?? [])->toHaveKeys(['type', 'title']);

    // The body folded, so nothing was too dynamic. The unread status is the analyser's own notice to make.
    $codes = array_map(static fn ($d): string => $d->code, $build['diagnostics']);
    expect($codes)->not->toContain('inferred-handler.too-dynamic');
});

/**
 * A renderer that set an explicit content type on a body too dynamic to fold — `$r = Problem::from($e);
 * …; return response()->json($r->toArray(), …, ['Content-Type' => …])` reaches the adapter exactly so.
 * `$hint` picks which of the two declines the build used to take: a throw carrying a status lands behind
 * the status fold, one carrying none in front of it.
 *
 * @return array{0: array<string, mixed>, 1: list<Diagnostic>}
 */
function unreadBodyBuild(Closure $render, string $exceptionFqcn, ?int $hint): array
{
    $symbol = registerRenderCallback($render, $exceptionFqcn);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        [$symbol => new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new UnknownT('payload not folded'),
                new UnknownT('status not folded'),
                new LiteralT('application/problem+json'),
            ]),
            new SourceLocation(''),
        )])],
        analysisOverrides: [
            'Workbench\\App\\Http\\Controllers\\FormController::show' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Workbench\\App\\Data\\FormData'), new SourceLocation(''))],
                throws: [new ThrownException($exceptionFqcn, $hint, [], ThrowConfidence::Certain, ThrowDisposition::Signal)],
            ),
        ],
    ));

    $result = generateDocument();

    return [$result->document->toArray(), $result->diagnostics];
}

/**
 * Why this answer is right, from the contract rather than from the code: this tier answers with what the
 * tiers behind it do not have, and none of them reads the renderer — they assert `application/json` off a
 * classification of the exception CLASS. The content type the render path folded is therefore a fact only
 * this tier holds, and it is a fact about the response the server
 * really sends. Declining published an error with no `content`, which says the error returns nothing;
 * a degraded answer has to stay true, and "a body of this media type, shape unknown" is the true one.
 */
it('states the media type it read, unconstrained, when the body did not fold', function (): void {
    [$document, $diagnostics] = unreadBodyBuild(
        static fn (ModelNotFoundException $e) => response()->json($e->getMessage() === '' ? [] : ['detail' => $e->getMessage()], 404, ['Content-Type' => 'application/problem+json']),
        MODEL_NOT_FOUND,
        404,
    );
    $responses = $document['paths']['/api/forms/{form}']['get']['responses'];

    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['404']['x-docuccino']['provenance'] ?? []);
    $content = resolveResponse($document, $responses['404'])['content'] ?? [];

    // The media type the renderer set — never `application/json`: this tier is ordered first, so a
    // renderer the build READ outranks any tier that answers off the exception's class alone.
    expect($producers)->toContain('integration:inferred-handler')
        ->and($content)->toHaveKey('application/problem+json')
        ->and($content)->not->toHaveKey('application/json');

    // An EMPTY schema, present. Absent would leave a generator choosing between "any body" and "none",
    // and `{type: object}` would claim a JSON object nothing in the build ever saw.
    expect($content['application/problem+json'])->toHaveKey('schema')
        ->and($content['application/problem+json']['schema'])->toBe([])
        ->and($content['application/problem+json'])->not->toHaveKey('example');

    // Nothing is minted for a shape nobody read, so no error component names this one.
    expect($document['components']['schemas'] ?? [])->not->toHaveKey('NotFound')
        ->and($responses['404']['x-docuccino']['facts'] ?? [])->not->toHaveKey('component');

    // Widening is not going quiet: the half that did not fold is still the author's to fix.
    expect(array_map(static fn ($d): string => $d->code, $diagnostics))->toContain('inferred-handler.too-dynamic');
});

it('files that media type under the classification when nothing read a status either', function (): void {
    // The decline in FRONT of the status fold, where the throw carried no status of its own. The key is
    // the exception's framework classification — the same key every tier behind would have used, so the
    // error is published once — and the media type rides along rather than being dropped with it.
    [$document, $diagnostics] = unreadBodyBuild(
        static fn (ProbeRejection $e) => response()->json($e->getMessage() === '' ? [] : ['detail' => $e->getMessage()], $e->getCode(), ['Content-Type' => 'application/problem+json']),
        ProbeRejection::class,
        null,
    );
    $responses = $document['paths']['/api/forms/{form}']['get']['responses'];

    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['500']['x-docuccino']['provenance'] ?? []);
    $content = resolveResponse($document, $responses['500'])['content'] ?? [];

    expect($responses)->toHaveKey('500')
        ->and($producers)->toContain('integration:inferred-handler')
        ->and($content)->toHaveKey('application/problem+json')
        ->and($content['application/problem+json']['schema'])->toBe([])
        // Never merged into the success response, which is what writing 200 would have done.
        ->and(array_map(static fn (array $r): string => $r['producer'], $responses['200']['x-docuccino']['provenance'] ?? []))
        ->not->toContain('integration:inferred-handler');

    expect(array_map(static fn ($d): string => $d->code, $diagnostics))->toContain('inferred-handler.too-dynamic');
});

it('leaves the media type unsaid where the renderer stated none, so the tiers behind still speak', function (): void {
    // The neighbour that must not move: with no content type folded either, this tier holds nothing the
    // chain lacks, and answering would end the chain on a response with no body. The framework tier takes
    // the 404 and — seeing a renderer it could not read — states the status alone.
    $symbol = registerRenderCallback(
        static fn (ModelNotFoundException $e) => response()->json(['dynamic' => true], 404),
        MODEL_NOT_FOUND,
    );
    app()->instance(TypeEngine::class, WorkbenchEngine::make([$symbol => new ActionAnalysis(returns: [new ReturnSite(
        new ClassT('Illuminate\\Http\\JsonResponse', [new UnknownT('payload not folded'), new UnknownT('status not folded')]),
        new SourceLocation(''),
    )])]));

    $document = generateDocument()->document->toArray();
    $responses = $document['paths']['/api/forms/{form}']['get']['responses'];

    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['404']['x-docuccino']['provenance'] ?? []);

    expect($producers)->toContain('integration:framework-errors')
        ->and(resolveResponse($document, $responses['404']))->not->toHaveKey('content');
});
