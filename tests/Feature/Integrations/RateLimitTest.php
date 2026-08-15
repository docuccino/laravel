<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\ProblemDetails\ProblemDetailsExceptionToResponse;
use Docuccino\Laravel\Integrations\RateLimit\RateLimitResponsesExtension;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * Real-path coverage (design §Phase 4 — rate limiting): the extension reads the actual gathered
 * route middleware through the pipeline and contributes a 429 with rate headers. The 429 is the same
 * for every throttled route whatever its limit; only a route throttling on a limiter name nothing
 * registered raises a diagnostic.
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
    // Two throttled routes sharing an identical 429 — headers included — collapse into one shared
    // component, so the headers resolve through the $ref along with the body.
    $response = resolveResponse($document, $operation['responses']['429']);
    expect($response['headers'])->toHaveKeys(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'])
        ->and($response['headers']['X-RateLimit-Limit']['schema'])->toBe(['type' => 'integer'])
        ->and($response['content']['application/json']['schema']['properties'])->toHaveKey('message');
});

it('documents routes on different limits with ONE shared 429 component', function (): void {
    // The payoff. `throttle:60,1` and `throttle:120,1` state the same contract — a 429 with rate-limit
    // headers — so they have to state it in the same bytes. Baking the limits in split that into an
    // `Error429` and an `Error429_2` whose description and content were byte-identical, plus two routes
    // that folded with nothing and stayed inline.
    /** @var Router $router */
    $router = app('router');
    $router->get('api/throttled-sixty', [FormController::class, 'index'])->middleware('throttle:60,1');
    $router->get('api/throttled-oneish', [FormController::class, 'index'])->middleware('throttle:120,1');
    $router->get('api/throttled-oneish-too', [FormController::class, 'index'])->middleware('throttle:120,1');
    $router->get('api/throttled-guests', [FormController::class, 'index'])->middleware('throttle:10|60');
    $router->get('api/throttled-named', [FormController::class, 'index'])->middleware('throttle:reports');

    $document = stubDocumentArray();

    $error429s = array_keys(array_filter(
        $document['components']['responses'] ?? [],
        static fn (string $name): bool => str_starts_with($name, 'Error429'),
        ARRAY_FILTER_USE_KEY,
    ));
    expect($error429s)->toBe(['Error429']);

    $paths = ['throttled-sixty', 'throttled-oneish', 'throttled-oneish-too', 'throttled-guests', 'throttled-named'];
    foreach ($paths as $path) {
        expect($document['paths']['/api/'.$path]['get']['responses']['429']['$ref'] ?? null)
            ->toBe('#/components/responses/Error429');
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
 * frozen 429's content plus whatever response components the run left registered.
 *
 * @param  list<ExceptionToResponse>  $mappers
 * @return array{content: array<string, mixed>, responses: array<string, array<string, mixed>>, schemas: array<string, array<string, mixed>>}
 */
function rateLimited429(string $errorResponses, array $mappers): array
{
    $components = new ComponentRegistry;
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/throttled', middleware: ['throttle:60,1']),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet,
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', [], errorResponses: $errorResponses),
        exceptionMappers: $mappers,
        components: $components,
    );

    $operation = new OperationDraft;
    (new RateLimitResponsesExtension(app(RateLimiter::class)))->handle($operation, $context);

    return [
        'content' => $operation->freeze()->responses['429']->content ?? [],
        'responses' => $components->responses(),
        'schemas' => $components->schemas(),
    ];
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

it('takes the 429 media type from the problem-details chain', function (): void {
    // The 429 is synthesized from middleware, not from a throw the engine saw, so the chain is never asked
    // about it unless this extension asks — and hardcoding Laravel's `{message}` would contradict a
    // document whose whole error contract is application/problem+json.
    $result = rateLimited429('problem-details', [new ProblemDetailsExceptionToResponse]);

    expect(array_keys($result['content']))->toBe(['application/problem+json'])
        ->and($result['content']['application/problem+json']['schema']['$ref'] ?? null)
        ->toBe('#/components/schemas/ProblemDetails');
});

it('leaves no response component behind after asking the chain, but keeps the schema it points at', function (): void {
    // The chain registers a shared `Problem429`, but this response inlines the content (its per-route
    // X-RateLimit-* headers can't ride a shared response), so nothing would ever $ref it — and an
    // unreferenced component makes a cold build's bytes differ from a warm-cache one's. The schema the
    // copied content DOES point at has to survive, or that $ref dangles.
    $result = rateLimited429('problem-details', [new ProblemDetailsExceptionToResponse]);

    expect($result['responses'])->toBe([])
        ->and($result['schemas'])->toHaveKey('ProblemDetails');
});

it('keeps the stock {message} body when the document documents no errors', function (): void {
    $result = rateLimited429('none', [new ProblemDetailsExceptionToResponse]);

    expect(array_keys($result['content']))->toBe(['application/json'])
        ->and($result['content']['application/json']['schema']['properties'] ?? [])->toHaveKey('message');
});

it('uses a chain answer that carries inline content verbatim', function (): void {
    $result = rateLimited429('problem-details', [chainAnswering(static function (ResponseDraft $draft): void {
        $draft->content('application/vnd.acme+json')->set('type', 'object', Contribution::integration('test-chain'));
    })]);

    expect(array_keys($result['content']))->toBe(['application/vnd.acme+json'])
        ->and($result['content']['application/vnd.acme+json']['schema']['type'] ?? null)->toBe('object');
});

it('falls back to the stock body when the chain answer cannot be read', function (?Closure $build): void {
    $result = rateLimited429('problem-details', $build === null ? [] : [chainAnswering($build)]);

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
        $draft->setRef('#/components/schemas/ProblemDetails', Contribution::integration('test-chain'));
    }],
    // A registered response that carries no body to copy.
    'a ref to a bodiless response' => [static function (ResponseDraft $draft, ComponentRegistry $components): void {
        $components->referenceResponse('Bodiless', ['description' => 'Too Many Requests']);
        $draft->setRef('#/components/responses/Bodiless', Contribution::integration('test-chain'));
    }],
]);
