<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallCondition;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Integrations\InferredHandler\RenderCallbackDigestContributor;
use Docuccino\Laravel\Tests\Fixtures\InferredHandler\ProbeFailureBody;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Workbench\App\Http\Controllers\FormController;

/**
 * The `respond()` finalizer, stub-side: a callback registered on the booted handler, its returns scripted
 * through the stub engine under the key the finalizer asks with. What the callback does to the workbench
 * form route's 404 — the framework's `{message}` as rendered — is what each test reads. Whether the real
 * engine recovers these returns, conditions and echoes from real code is the fixture group's
 * `RespondCallbackTest`.
 */
const RESPOND_NOT_FOUND = ModelNotFoundException::class;

/** The RFC 9457 body a callback rebuilds from the rendered response, at a status it read back off it. */
function respondProblem(DType $status = new UnknownT('status not folded'), string $mediaType = 'application/problem+json'): ClassT
{
    return new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([
            new ArrayShapeField('type', new LiteralT('about:blank')),
            new ArrayShapeField('title', ScalarT::string()),
            new ArrayShapeField('status', ScalarT::int()),
        ]),
        $status,
        new LiteralT($mediaType),
    ]);
}

/**
 * The workbench form route's 404 as published, with a `respond()` callback returning `$returns`.
 *
 * @param  list<ReturnSite>  $returns
 *                                     `deferred` is whether the build told the author it could not read what the callback makes of THIS
 *                                     exception — the other routes' throws have no scripted returns, so they are reported whatever this does.
 * @return array{responses: array<string, mixed>, document: array<string, mixed>, deferred: bool}
 */
function respondedFormErrors(array $returns): array
{
    $symbol = registerRespondCallback(
        static fn (Response $response, Throwable $e, Request $request): Response => $response,
        RESPOND_NOT_FOUND,
    );
    app()->instance(TypeEngine::class, WorkbenchEngine::make([$symbol => new ActionAnalysis(returns: $returns)]));

    $result = generateDocument();
    $document = $result->document->toArray();

    return [
        'responses' => $document['paths']['/api/forms/{form}']['get']['responses'],
        'document' => $document,
        'deferred' => array_filter(
            $result->diagnostics,
            static fn ($d): bool => $d->code === 'inferred-handler.too-dynamic' && str_contains($d->message, RESPOND_NOT_FOUND),
        ) !== [],
    ];
}

function respondEcho(CallCondition ...$conditions): ReturnSite
{
    return new ReturnSite(new ClassT(Response::class), new SourceLocation(''), returnsParameter: 'response', conditions: array_values($conditions));
}

function respondRewrite(ClassT $type, CallCondition ...$conditions): ReturnSite
{
    return new ReturnSite($type, new SourceLocation(''), conditions: array_values($conditions));
}

it('publishes what the callback returns in place of the rendered body, at the rendered status', function (): void {
    $out = respondedFormErrors([respondRewrite(respondProblem())]);

    $media = resolveResponse($out['document'], $out['responses']['404'])['content'] ?? [];
    $schema = errorSchemaOf($out['document'], '404', 'application/problem+json');

    // Replaced, not joined: the framework's `{message}` is a body this server never sends.
    expect(array_keys($media))->toBe(['application/problem+json'])
        ->and($schema['properties'] ?? [])->toHaveKeys(['type', 'title', 'status'])
        ->and($schema['required'] ?? [])->toBe(['type', 'title', 'status']);
});

it('keeps the rendered response where every reachable return hands it back unchanged', function (): void {
    $without = generateWithoutRespond();
    $out = respondedFormErrors([respondEcho()]);

    expect($out['responses']['404'])->toBe($without['paths']['/api/forms/{form}']['get']['responses']['404'])
        ->and($out['deferred'])->toBeFalse();
});

it('settles a branch on the request path for the route being documented', function (string $pattern, bool $rewritten): void {
    $out = respondedFormErrors([
        respondRewrite(respondProblem(), new CallCondition('request', 'is', [$pattern], true)),
        respondEcho(new CallCondition('request', 'is', [$pattern], false)),
    ]);

    $media = array_keys(resolveResponse($out['document'], $out['responses']['404'])['content'] ?? []);

    expect($media)->toBe($rewritten ? ['application/problem+json'] : ['application/json']);
})->with([
    'a pattern the route matches' => ['api/*', true],
    'a pattern the route cannot match' => ['admin/*', false],
]);

it('publishes both answers beside each other where the branch needs a live request to settle', function (): void {
    $out = respondedFormErrors([
        respondRewrite(respondProblem(), new CallCondition('request', 'expectsJson', [], true)),
        respondEcho(new CallCondition('request', 'expectsJson', [], false)),
    ]);

    $media = array_keys(resolveResponse($out['document'], $out['responses']['404'])['content'] ?? []);

    // The rendered body first, then the callback's: the server sends either, and a client told only one
    // of them would reject the other at run time.
    expect($media)->toBe(['application/json', 'application/problem+json'])
        ->and(errorSchemaOf($out['document'], '404', 'application/json')['properties'] ?? [])->toHaveKey('message')
        ->and(errorSchemaOf($out['document'], '404', 'application/problem+json')['properties'] ?? [])->toHaveKey('title');
});

it('widens a media type two alternatives both send, rather than merging their bodies', function (): void {
    $out = respondedFormErrors([
        respondRewrite(respondProblem(mediaType: 'application/json'), new CallCondition('request', 'expectsJson', [], true)),
        respondEcho(new CallCondition('request', 'expectsJson', [], false)),
    ]);

    // `{message}` or `{type, title, status}` under one key: a schema carrying the members of both would
    // describe a body neither alternative sends.
    expect(mediaOf($out['document'], '404', 'application/json'))->toBe(['schema' => []]);
});

it('ignores a branch on the rendered status that this response is not rendered at', function (): void {
    $out = respondedFormErrors([
        respondRewrite(new ClassT('Illuminate\\Http\\RedirectResponse'), new CallCondition('response', 'getStatusCode', [], 419)),
        respondEcho(),
    ]);

    $without = generateWithoutRespond();

    // The redirect is for an expired session and nothing else, so a 404 is untouched and nothing is said.
    expect($out['responses']['404'])->toBe($without['paths']['/api/forms/{form}']['get']['responses']['404'])
        ->and($out['deferred'])->toBeFalse();
});

it('moves the response to a status the callback states for itself', function (): void {
    $out = respondedFormErrors([respondRewrite(respondProblem(new LiteralT(410)))]);

    expect($out['responses'])->toHaveKey('410')
        ->and($out['responses'])->not->toHaveKey('404');
});

it('leaves the body unsaid and says why where the only reachable return is one it cannot read', function (): void {
    $out = respondedFormErrors([respondRewrite(new ClassT('Illuminate\\Http\\RedirectResponse'))]);

    $response = resolveResponse($out['document'], $out['responses']['404']);

    // Whatever the callback sends, it is not the framework's `{message}`, so the status stands alone.
    expect($response)->not->toHaveKey('content')
        ->and($response['description'] ?? null)->toBe('Not Found')
        ->and($out['deferred'])->toBeTrue();
});

it('keeps the rendered response and says why where a return beside it cannot be read', function (): void {
    $without = generateWithoutRespond();
    $out = respondedFormErrors([respondRewrite(new ClassT('Illuminate\\Http\\RedirectResponse')), respondEcho()]);

    expect($out['responses']['404'])->toBe($without['paths']['/api/forms/{form}']['get']['responses']['404'])
        ->and($out['deferred'])->toBeTrue();
});

it('keeps the rendered response and says why where rewrites land at different statuses', function (): void {
    $out = respondedFormErrors([
        respondRewrite(respondProblem(new LiteralT(410)), new CallCondition('request', 'expectsJson', [], true)),
        respondRewrite(respondProblem(new LiteralT(409)), new CallCondition('request', 'expectsJson', [], false)),
    ]);

    expect($out['responses'])->toHaveKey('404')
        ->and(array_keys(resolveResponse($out['document'], $out['responses']['404'])['content'] ?? []))->toBe(['application/json'])
        ->and($out['deferred'])->toBeTrue();
});

it('publishes a rewritten JSON body it could not read as JSON of unknown shape', function (): void {
    $out = respondedFormErrors([respondRewrite(new ClassT('Illuminate\\Http\\JsonResponse', [new UnknownT('payload not folded'), new UnknownT('status not folded')]))]);

    expect(mediaOf($out['document'], '404', 'application/json'))->toBe(['schema' => []])
        ->and($out['deferred'])->toBeTrue();
});

it('keeps the rendered response and says why where the callback could not be analysed at all', function (): void {
    $without = generateWithoutRespond();
    $out = respondedFormErrors([]);

    expect($out['responses']['404'])->toBe($without['paths']['/api/forms/{form}']['get']['responses']['404'])
        ->and($out['deferred'])->toBeTrue();
});

it('records the provenance of a replaced response as the tier that read the callback', function (): void {
    $out = respondedFormErrors([respondRewrite(respondProblem())]);

    $producers = array_map(static fn (array $r): string => $r['producer'], $out['responses']['404']['x-docuccino']['provenance'] ?? []);

    expect($producers)->toContain('integration:inferred-handler')
        ->and($producers)->not->toContain('integration:framework-errors');
});

/** The same build with no `respond()` callback registered. */
function generateWithoutRespond(): array
{
    bindStubEngine();

    return generateDocument()->document->toArray();
}

it('withdraws the component a replaced body hoisted, so nothing is published for a body never sent', function (): void {
    $render = registerRenderCallback(
        static fn (NotFoundHttpException $e) => response()->json(new ProbeFailureBody('gone', 1), 404),
        RESPOND_NOT_FOUND,
    );
    $respond = registerRespondCallback(
        static fn (Response $response, Throwable $e, Request $request): Response => $response,
        RESPOND_NOT_FOUND,
    );

    $build = static function (array $returns) use ($render, $respond): array {
        app()->instance(TypeEngine::class, WorkbenchEngine::make(
            [
                $render => new ActionAnalysis(returns: [new ReturnSite(
                    new ClassT('Illuminate\\Http\\JsonResponse', [new ClassT(ProbeFailureBody::class), new LiteralT(404)]),
                    new SourceLocation(''),
                )]),
                $respond => new ActionAnalysis(returns: $returns),
            ],
            classOverrides: [ProbeFailureBody::class => new ClassMetadata(ProbeFailureBody::class, [
                new PropertyMetadata('reason', ScalarT::string()),
                new PropertyMetadata('attempt', ScalarT::int()),
            ])],
        ));

        return generateDocument()->document->toArray();
    };

    // The control: handed back unchanged, the rendered body is sent, and its component with it.
    expect(array_keys($build([respondEcho()])['components']['schemas'] ?? []))->toContain('ProbeFailureBody');

    $replaced = $build([respondRewrite(respondProblem())]);

    expect(array_keys($replaced['components']['schemas'] ?? []))->not->toContain('ProbeFailureBody')
        ->and(array_keys(resolveResponse($replaced, $replaced['paths']['/api/forms/{form}']['get']['responses']['404'])['content'] ?? []))
        ->toBe(['application/problem+json']);
});

it('reports on a warm build exactly what a cold one reports', function (): void {
    $symbol = registerRespondCallback(
        static fn (Response $response, Throwable $e, Request $request): Response => $response,
        RESPOND_NOT_FOUND,
    );
    $engine = static fn (): TypeEngine => WorkbenchEngine::make([$symbol => new ActionAnalysis(returns: [
        respondRewrite(respondProblem(), new CallCondition('request', 'is', ['api/*'], true)),
        respondRewrite(new ClassT('Illuminate\\Http\\RedirectResponse'), new CallCondition('request', 'is', ['api/*'], false)),
        respondEcho(new CallCondition('request', 'is', ['api/*'], false)),
    ])]);
    $routes = static fn (Router $router) => $router->get('api/probe-forms/{form}', [FormController::class, 'show']);

    $warm = assertWarmEqualsCold($routes, $routes, $engine);

    expect(array_keys($warm->document->toArray()['paths']['/api/probe-forms/{form}']['get']['responses']['404']['content'] ?? []))
        ->toBe(['application/problem+json']);
});

it('reshapes a response synthesized from middleware too, since the framework renders that as well', function (string $path, string $status, string $exception): void {
    $symbol = registerRespondCallback(
        static fn (Response $response, Throwable $e, Request $request): Response => $response,
        $exception,
    );
    app()->instance(TypeEngine::class, WorkbenchEngine::make([$symbol => new ActionAnalysis(returns: [respondRewrite(respondProblem())])]));

    $document = generateDocument()->document->toArray();
    $response = resolveResponse($document, $document['paths'][$path]['get']['responses'][$status] ?? []);

    expect(array_keys($response['content'] ?? []))->toBe(['application/problem+json']);
})->with([
    'the throttled 429' => ['/api/rate-limited', '429', 'Illuminate\\Http\\Exceptions\\ThrottleRequestsException'],
    'the unauthenticated 401' => ['/api/guarded-forms', '401', 'Illuminate\\Auth\\AuthenticationException'],
]);

it('keys every fragment on the respond() callback, so registering or moving one rebuilds the routes', function (): void {
    /** @var Handler $handler */
    $handler = app(ExceptionHandler::class);
    $digest = static fn (): string => (new RenderCallbackDigestContributor($handler))->digest();

    $none = $digest();
    $handler->respondUsing(static fn (Response $response): Response => $response);
    $one = $digest();
    $handler->respondUsing(static fn (Response $response, Throwable $e): Response => $response);
    $other = $digest();

    expect($one)->not->toBe($none)
        ->and($other)->not->toBe($one);
});
