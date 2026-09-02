<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\ResponseObject;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Exceptions\DefaultExceptionToResponse;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerDeferralLog;
use Docuccino\Laravel\Integrations\InferredHandler\InferredHandlerExceptionToResponse;
use Docuccino\Laravel\Integrations\RateLimit\RateLimitResponsesExtension;
use Docuccino\Laravel\Integrations\Support\AppRenderedErrors;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;
use Workbench\App\Http\Controllers\IgnoredResponsesController;

/**
 * Real-path coverage: the extension reads the actual gathered route middleware through the pipeline and
 * contributes a 429 with rate headers. The 429 is the same for every throttled route whatever its
 * limit; only a route throttling on a limiter name nothing registered raises a diagnostic.
 */
/** @return array{array<string, mixed>, array<string, mixed>} the emitted document and the GET operation */
function throttledOperation(string $path): array
{
    bindStubEngine();
    $document = generateDocument()->document->toArray();

    return [$document, $document['paths']['/'.$path]['get'] ?? []];
}

// Own prefix, own cleanup: the suite runs in parallel, so globbing another file's fragment dirs would
// delete a cache another process is mid-test on.
afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/docuccino-ratelimit-fragments-*') ?: [] as $dir) {
        array_map('unlink', glob($dir.'/*') ?: []);
        @unlink($dir.'/.gitignore');
        @rmdir($dir);
    }
});

it('adds a 429 with Retry-After + X-RateLimit-* headers for a numeric throttle', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/throttled', [FormController::class, 'index'])->middleware('throttle:60,1');

    [$document, $operation] = throttledOperation('api/throttled');

    expect($operation['responses'])->toHaveKey('429');
    // Resolved through the components, because every throttled 429 is identical down to its headers and
    // so the whole response hoists — the operation carries a bare `$ref`. The row after this one pins
    // that; this one pins what the resolved response says.
    $response = resolveResponse($document, $operation['responses']['429']);
    expect($response['headers'])->toHaveKeys(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'])
        ->and($response['headers']['X-RateLimit-Limit']['schema'])->toBe(['type' => 'integer'])
        ->and(resolveSchema($document, $response['content']['application/json']['schema'])['properties'] ?? [])
        ->toHaveKey('message');
});

it('documents routes on different limits with ONE shared 429 shape', function (): void {
    // The payoff. `throttle:60,1` and `throttle:120,1` state the same contract — a 429 with rate-limit
    // headers — so they have to state it in the same bytes. Baking each route's limits into the
    // description or the header schemas splits one shape into several byte-identical ones, and leaves
    // the routes nothing to fold with.
    /** @var Router $router */
    $router = app('router');
    $router->get('api/throttled-sixty', [FormController::class, 'index'])->middleware('throttle:60,1');
    $router->get('api/throttled-oneish', [FormController::class, 'index'])->middleware('throttle:120,1');
    $router->get('api/throttled-oneish-too', [FormController::class, 'index'])->middleware('throttle:120,1');
    $router->get('api/throttled-guests', [FormController::class, 'index'])->middleware('throttle:10|60');
    $router->get('api/throttled-named', [FormController::class, 'index'])->middleware('throttle:reports');

    $document = stubDocumentArray();

    $error429s = array_keys(array_filter(
        $document['components']['schemas'] ?? [],
        static fn (string $name): bool => str_starts_with($name, 'TooManyRequests'),
        ARRAY_FILTER_USE_KEY,
    ));
    // One shape, and it keeps the name the integration declared for it: nothing contests it, so no
    // route sees a discriminator.
    expect($error429s)->toBe(['TooManyRequests']);

    // Every throttled 429 is identical — headers included — so the whole response hoists as well, and
    // the one component points at the one shape.
    $shared = $document['components']['responses']['TooManyRequests'] ?? [];

    expect(array_keys(array_filter(
        $document['components']['responses'] ?? [],
        static fn (string $name): bool => str_starts_with($name, 'TooManyRequests'),
        ARRAY_FILTER_USE_KEY,
    )))->toBe(['TooManyRequests'])
        ->and($shared)->toHaveKey('description')
        ->and($shared['headers'] ?? [])->toHaveKey('X-RateLimit-Limit')
        ->and($shared['content']['application/json']['schema'] ?? null)
        ->toBe(['$ref' => '#/components/schemas/TooManyRequests']);

    $paths = ['throttled-sixty', 'throttled-oneish', 'throttled-oneish-too', 'throttled-guests', 'throttled-named'];
    foreach ($paths as $path) {
        expect($document['paths']['/api/'.$path]['get']['responses']['429']['$ref'] ?? null)
            ->toBe('#/components/responses/TooManyRequests');
    }
});

it('reports a throttle on a limiter name nothing registered, and documents the same 429 anyway', function (): void {
    // The route is broken — Laravel's named-limiter lookup misses and `resolveMaxAttempts` casts
    // "reports" to 0, so every guest request 429s — but that is an app bug, not a documentation one:
    // the 429 the operation states is exactly the one a registered limiter would state.
    /** @var Router $router */
    $router = app('router');
    $router->get('api/named-throttle', [FormController::class, 'index'])->middleware('throttle:reports');

    bindStubEngine();
    $result = generateDocument();
    $document = $result->document->toArray();
    $operation = $document['paths']['/api/named-throttle']['get'] ?? [];

    expect($operation['responses'])->toHaveKey('429');
    expect(resolveResponse($document, $operation['responses']['429'])['headers']['X-RateLimit-Limit']['schema'])
        ->toBe(['type' => 'integer']);

    $reported = diagnosticsCoded($result->diagnostics, 'rate-limit.unregistered-limiter');
    expect($reported)->toHaveCount(1)
        ->and($reported[0]->message)->toContain('"reports"')
        ->and($reported[0]->help)->toContain("RateLimiter::for('reports'")
        ->and($reported[0]->routeSignature)->toBe('GET /api/named-throttle')
        ->and($result->has(Severity::Info))->toBeTrue();
});

it('still reports an unregistered limiter on a route that ignores the 429', function (): void {
    // An `#[IgnoreResponse(429)]` says what to document; it says nothing about whether the limiter the
    // route names exists. Consulting it above this check hid the app bug — every guest request 429s —
    // behind an attribute about documentation. The multiple-throttles notice goes the other way: it
    // reports what the DOCUMENT does with several throttles, so an operation documenting no 429 has
    // nothing to under-report and would otherwise state a 429 that is not there.
    /** @var Router $router */
    $router = app('router');
    $router->get('api/ignored-named-throttle', [IgnoredResponsesController::class, 'throttled'])
        ->middleware(['throttle:reports', 'throttle:10,1']);

    bindStubEngine();
    $result = generateDocument();

    $reported = diagnosticsCoded($result->diagnostics, 'rate-limit.unregistered-limiter');

    expect($reported)->toHaveCount(1)
        ->and($reported[0]->message)->toContain('"reports"')
        ->and($reported[0]->routeSignature)->toBe('GET /api/ignored-named-throttle')
        ->and(diagnosticsCoded($result->diagnostics, 'rate-limit.multiple-throttles'))->toBe([])
        ->and($result->document->toArray()['paths']['/api/ignored-named-throttle']['get']['responses'] ?? [])
        ->not->toHaveKey('429');
});

it('says which throttle the one documented 429 came from when a route carries several', function (): void {
    // OpenAPI has one 429 per operation, so a route behind two throttles documents the first and the
    // reader is told the rest are still enforced — silence here reads as "one limit", which is a lie
    // about what the server does.
    /** @var Router $router */
    $router = app('router');
    $router->get('api/double-throttle', [FormController::class, 'index'])->middleware(['throttle:60,1', 'throttle:10,1']);

    bindStubEngine();
    $result = generateDocument();

    $reported = diagnosticsCoded($result->diagnostics, 'rate-limit.multiple-throttles');

    expect($reported)->toHaveCount(1)
        ->and($reported[0]->message)->toContain('2 throttle middleware')
        ->and($reported[0]->routeSignature)->toBe('GET /api/double-throttle')
        ->and($result->document->toArray()['paths']['/api/double-throttle']['get']['responses'])->toHaveKey('429');
});

it('reports nothing for a registered named limiter, however dynamic it is', function (): void {
    // A conditional limiter documents exactly what a literal one does, so there is nothing to say about
    // either. Registration is the only thing checked.
    app(RateLimiter::class)->for('reports', static fn (Request $request): Limit => $request->user() ? Limit::none() : Limit::perMinute(30));

    /** @var Router $router */
    $router = app('router');
    $router->get('api/registered-throttle', [FormController::class, 'index'])->middleware('throttle:reports');

    bindStubEngine();
    $result = generateDocument();
    $document = $result->document->toArray();
    $operation = $document['paths']['/api/registered-throttle']['get'] ?? [];

    $headers = resolveResponse($document, $operation['responses']['429'] ?? [])['headers'] ?? [];
    expect($headers['X-RateLimit-Limit']['schema'])->toBe(['type' => 'integer'])
        ->and($headers['Retry-After']['schema'])->toBe(['type' => 'integer'])
        ->and(diagnosticsCoded($result->diagnostics, 'rate-limit.unregistered-limiter'))->toBe([]);
});

it('replays the unregistered-limiter report on a warm cache hit, and retires it once the limiter exists', function (): void {
    // The diagnostic lives inside the fragment, and the route depends on no file that registering a
    // limiter would touch — so a warm build has to replay it, and only the environment digest can
    // retire it. Under-key that digest and the stale report outlives the fix.
    $dir = sys_get_temp_dir().'/docuccino-ratelimit-fragments-'.uniqid('', true);
    config()->set('docuccino.cache.enabled', true);
    config()->set('docuccino.cache.path', $dir);

    /** @var Router $router */
    $router = app('router');
    $router->get('api/warm-throttle', [FormController::class, 'index'])->middleware('throttle:reports');

    bindStubEngine();

    $cold = generateDocument();
    $warm = generateDocument();

    expect(diagnosticsCoded($cold->diagnostics, 'rate-limit.unregistered-limiter'))->toHaveCount(1)
        ->and(diagnosticsCoded($warm->diagnostics, 'rate-limit.unregistered-limiter'))->toHaveCount(1);

    app(RateLimiter::class)->for('reports', static fn (): Limit => Limit::perMinute(30));

    expect(diagnosticsCoded(generateDocument()->diagnostics, 'rate-limit.unregistered-limiter'))->toBe([]);
});

it('adds no 429 to an unthrottled route', function (): void {
    [, $operation] = throttledOperation('api/forms');

    expect($operation['responses'] ?? [])->not->toHaveKey('429');
});

// --- The 429 body comes from the error-response chain -----------------------

/**
 * Run the extension over a throttled route with the given error style and mapper chain, and hand back the
 * frozen 429 plus whatever the run left behind: the response components, and the route notes, which are
 * the other thing a consultation can leave behind and must not.
 *
 * @param  list<ExceptionToResponse>  $mappers
 * @return array{response: ResponseObject, content: array<string, mixed>, responses: array<string, array<string, mixed>>, schemas: array<string, array<string, mixed>>, notes: array<string, array<string, list<string>>>}
 */
function rateLimited429(string $errorResponses, array $mappers, ?TypeEngine $engine = null): array
{
    $context = rateLimitContext($errorResponses, $mappers, $engine);

    $operation = new OperationDraft;
    (new RateLimitResponsesExtension(app(RateLimiter::class)))->handle($operation, $context);

    $response = $operation->freeze()->responses['429'];

    return [
        'response' => $response,
        'content' => $response->content ?? [],
        'responses' => $context->components->responses(),
        'schemas' => $context->components->schemas(),
        'notes' => $context->notes()->all(),
    ];
}

/**
 * The throttled route the rows above run over, as a context of its own — so a row that expects NO 429 can
 * read the components and the notes back without reaching for a response the document does not carry.
 *
 * @param  list<ExceptionToResponse>  $mappers
 */
function rateLimitContext(string $errorResponses, array $mappers, ?TypeEngine $engine = null): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/throttled', middleware: ['throttle:60,1']),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet,
        engine: $engine ?? new NullTypeEngine,
        document: new DocumentConfig('default', [], errorResponses: $errorResponses),
        extensions: new ResolvedExtensions(
            exceptionToResponse: $mappers,
        ),
        components: new ComponentRegistry,
    );
}

/**
 * The real error chain an application with a handler of its own presents to this 429: the inferred-handler
 * tier reading a renderer registered for the throttle exception, then the terminal fallback. `$returns` is
 * what the engine recovered from that renderer.
 *
 * @return array{list<ExceptionToResponse>, TypeEngine}
 */
function throttleRenderChain(ReturnSite ...$returns): array
{
    $symbol = registerRenderCallback(
        static fn (ThrottleRequestsException $e) => response('slow down', 429),
        ThrottleRequestsException::class,
    );

    return [
        [app(InferredHandlerExceptionToResponse::class), new DefaultExceptionToResponse],
        WorkbenchEngine::make([$symbol => new ActionAnalysis(returns: $returns)]),
    ];
}

/**
 * A mapper answering the way an application's own error handling does when the build read a shared body
 * out of it: a `$ref` to a response component it registered, whose content names a media type of the
 * application's own and points at the schema for it. The 429 has to copy that content rather than the
 * reference, since the reference carries none of this route's headers.
 */
function chainReferencingSharedError(): ExceptionToResponse
{
    return chainAnswering(static function (ResponseDraft $draft, ComponentRegistry $components): void {
        $schema = $components->reference('AppProblem', ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]], 'test:app-problem');
        $components->referenceResponse('AppProblem429', [
            'description' => 'Too Many Requests',
            'content' => ['application/problem+json' => ['schema' => $schema]],
        ]);
        $draft->setRef('#/components/responses/AppProblem429', Contribution::integration('test-chain'));
    });
}

/** A mapper that always answers, with whatever the callback puts on the draft. */
function chainAnswering(Closure $build): ExceptionToResponse
{
    return new class($build) implements ExceptionToResponse
    {
        public function __construct(private readonly Closure $build) {}

        public function supports(ThrownException $exception, RouteContext $context): bool
        {
            return true;
        }

        public function producer(): string
        {
            return 'integration:test-chain';
        }

        public function toResponse(ThrownException $exception, RouteContext $context, ComponentRegistry $components): ?ResponseDraft
        {
            $draft = new ResponseDraft('429');
            ($this->build)($draft, $components);

            return $draft;
        }
    };
}

it('takes the 429 media type from the error-response chain', function (): void {
    // The 429 is synthesized from middleware, not from a throw the engine saw, so the chain is never asked
    // about it unless this extension asks — and hardcoding Laravel's `{message}` would contradict an
    // application whose own handler renders application/problem+json for the very same exception.
    $result = rateLimited429('default', [chainReferencingSharedError()]);

    expect(array_keys($result['content']))->toBe(['application/problem+json'])
        ->and($result['content']['application/problem+json']['schema']['$ref'] ?? null)
        ->toBe('#/components/schemas/AppProblem');
});

it('leaves no response component behind after asking the chain, but keeps the schema it points at', function (): void {
    // The chain registers a shared response, but this 429 inlines the content (its per-route
    // X-RateLimit-* headers can't ride a shared response), so nothing would ever $ref it — and an
    // unreferenced component makes a cold build's bytes differ from a warm-cache one's. The schema the
    // copied content DOES point at has to survive, or that $ref dangles.
    $result = rateLimited429('default', [chainReferencingSharedError()]);

    expect($result['responses'])->toBe([])
        ->and($result['schemas'])->toHaveKey('AppProblem');
});

it('documents no 429 at all when the document documents no errors', function (): void {
    // `error_responses => 'none'` is a statement about the whole document: it publishes no error response
    // Docuccino synthesized, and the 429 is one — synthesized from middleware rather than from a throw,
    // but an error response either way, which is why the configuration reference lists it among the
    // implicit responses the switch turns off. It used to publish the 429 with Laravel's stock `{message}`
    // body under `none`, because only the chain CONSULT was gated and the response itself was not: a
    // switch named `none` that yields a response with a framework-shaped body in it.
    //
    // The rate headers go with it. They are facts about throttling rather than about the error body, and
    // they have nowhere to be published except on a response this document does not carry; a route that
    // wants its 429 kept while the rest go is asking for `#[IgnoreResponse]` on the others, not for this.
    $operation = new OperationDraft;
    $context = rateLimitContext('none', [chainReferencingSharedError()]);

    (new RateLimitResponsesExtension(app(RateLimiter::class)))->handle($operation, $context);

    expect($operation->freeze()->responses)->toBe([])
        // Nothing is left behind either: no shared component, and no note asking an author to fix a
        // callback for a body nobody will see.
        ->and($context->components->responses())->toBe([])
        ->and($context->notes()->all())->toBe([]);
});

it('keeps a chain answer that names a media type and constrains nothing under it', function (): void {
    // The chain can answer with the media type alone — a handler whose body the build could not read but
    // whose content type it could. Copying it keyword by keyword finds no keyword, and the 429 would come
    // back stating `application/json` `{message}`: the framework shape over a document that just refuted
    // it. The representation is the fact, so it survives with an empty schema under it.
    $result = rateLimited429('default', [chainAnswering(static function (ResponseDraft $draft): void {
        $draft->content('application/problem+json');
    })]);

    expect(array_keys($result['content']))->toBe(['application/problem+json'])
        ->and($result['content']['application/problem+json'])->toBe(['schema' => []]);
});

it('uses a chain answer that carries inline content verbatim', function (): void {
    $result = rateLimited429('default', [chainAnswering(static function (ResponseDraft $draft): void {
        $draft->content('application/vnd.acme+json')->set('type', 'object', Contribution::integration('test-chain'));
    })]);

    expect(array_keys($result['content']))->toBe(['application/vnd.acme+json'])
        ->and($result['content']['application/vnd.acme+json']['schema']['type'] ?? null)->toBe('object');
});

it('withholds the stock body where the application renders the throttle itself', function (): void {
    // The 429 is the FOURTH producer of a framework-shaped error body, and the same fact stands the other
    // three down: an application whose own handler renders this exception, and whose result the build could
    // not read, has refuted `{"message": string}`. Filling the gap here would re-assert on every throttled
    // route precisely what the framework-defaults tier and the terminal fallback withhold everywhere else —
    // and it would do so over code that says otherwise, which is the one thing a degraded answer may not do.
    [$mappers, $engine] = throttleRenderChain(new ReturnSite(new ClassT('Illuminate\\Http\\Response'), new SourceLocation('')));

    $result = rateLimited429('default', $mappers, $engine);

    // The status, its reason and the rate headers are this integration's own and untouched — only the body
    // the chain refuted goes unsaid.
    expect($result['content'])->toBe([])
        ->and($result['response']->description)->toBe('Too Many Requests — the rate limit for this endpoint has been exceeded.')
        ->and(array_keys($result['response']->headers ?? []))->toContain('Retry-After');
});

it('keeps the stock body where that same renderer hands the throttle back to the framework', function (): void {
    // The gate open, on the same chain: a `return null` arm is the application deferring to the framework,
    // so the framework's own shape is the truth and the 429 states it. Paired with the row above because a
    // gate that never opens strips the body off every throttled route in every application.
    [$mappers, $engine] = throttleRenderChain(new ReturnSite(new NullT, new SourceLocation('')));

    $result = rateLimited429('default', $mappers, $engine);

    expect(array_keys($result['content']))->toBe(['application/json'])
        ->and($result['content']['application/json']['schema']['properties'] ?? [])->toHaveKey('message');
});

it('leaves no route note behind when the chain answer is discarded, and keeps the one it uses', function (): void {
    // Asking the chain is a READ, and the components rollback has always said so. A note is the same kind
    // of fact reaching the document by another road — the deferral summary asks an author to make a
    // callback foldable — so a note written while building an answer nobody sees is a fact about nothing.
    // The rule is {@see IgnoredResponses::mapThrow()}'s: roll back where the answer is DISCARDED, and stand
    // where it is used. Both halves here, because a rollback that took the second with it would lose the
    // one report an author can act on for a 429 whose body really was lost.
    //
    // The discarded half needs a chain that writes a note and then lands nothing, which is a chain with no
    // terminal tier behind the one that declined — an application's own mapper ordering, since the tier
    // that declines here is the same one that records.
    [$mappers, $engine] = throttleRenderChain(new ReturnSite(new ClassT('Illuminate\\Http\\Response'), new SourceLocation('')));
    $discarded = rateLimited429('default', [$mappers[0]], $engine);
    $used = rateLimited429('default', $mappers, $engine);

    // Anti-vacuity: the same consultation really does write both notes, so the emptiness above is a
    // rollback rather than a route nothing ever recorded anything on.
    expect($discarded['notes'])->toBe([])
        ->and(array_keys($used['notes']))->toBe([AppRenderedErrors::CHANNEL, HandlerDeferralLog::CHANNEL]);
});

it('falls back to the stock body when the chain answer cannot be read', function (?Closure $build): void {
    $result = rateLimited429('default', $build === null ? [] : [chainAnswering($build)]);

    expect(array_keys($result['content']))->toBe(['application/json'])
        ->and($result['content']['application/json']['schema']['properties'] ?? [])->toHaveKey('message');
})->with([
    // Nothing in the chain supports the throttle exception at all.
    'no mapper answers' => [null],
    // A pointer at a response component nobody registered.
    'a ref to an unregistered response' => [static function (ResponseDraft $draft): void {
        $draft->setRef('#/components/responses/NeverRegistered', Contribution::integration('test-chain'));
    }],
    // A pointer that isn't a response component at all.
    'a ref outside components.responses' => [static function (ResponseDraft $draft): void {
        $draft->setRef('#/components/schemas/AppProblem', Contribution::integration('test-chain'));
    }],
    // A registered response that carries no body to copy.
    'a ref to a bodiless response' => [static function (ResponseDraft $draft, ComponentRegistry $components): void {
        $components->referenceResponse('Bodiless', ['description' => 'Too Many Requests']);
        $draft->setRef('#/components/responses/Bodiless', Contribution::integration('test-chain'));
    }],
]);
