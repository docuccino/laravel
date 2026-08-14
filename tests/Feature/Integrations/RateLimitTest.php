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
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\ProblemDetails\ProblemDetailsExceptionToResponse;
use Docuccino\Laravel\Integrations\RateLimit\RateLimitResponsesExtension;
use Docuccino\Laravel\Tests\Support\StubTraceScope;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use PhpParser\Node;
use PhpParser\ParserFactory;
use Workbench\App\Http\Controllers\FormController;

/**
 * Real-path coverage (design §Phase 4 — rate limiting): the extension reads the actual gathered
 * route middleware through the pipeline and contributes a 429 with rate headers. A numeric throttle
 * documents the numbers; a named limiter degrades to a numberless 429 + an info diagnostic.
 */
/** @return array{array<string, mixed>, array<string, mixed>} the emitted document and the GET operation */
function throttledOperation(string $path): array
{
    bindStubEngine();
    $document = generateDocument()->document->toArray();

    return [$document, $document['paths']['/'.$path]['get'] ?? []];
}

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
        ->and($response['headers']['X-RateLimit-Limit']['schema']['example'])->toBe(60)
        ->and($response['content']['application/json']['schema']['properties'])->toHaveKey('message');
});

it('documents a named limiter 429 without numbers and reports an info diagnostic', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/named-throttle', [FormController::class, 'index'])->middleware('throttle:reports');

    bindStubEngine();
    $result = generateDocument();
    $operation = $result->document->toArray()['paths']['/api/named-throttle']['get'] ?? [];

    expect($operation['responses'])->toHaveKey('429');
    expect($operation['responses']['429']['headers']['X-RateLimit-Limit']['schema'])->toBe(['type' => 'integer']);

    $codes = array_map(static fn ($d): string => $d->code, $result->diagnostics);
    expect($codes)->toContain('rate-limit.dynamic-limit')
        ->and($result->has(Severity::Info))->toBeTrue();
});

it('reflects a registered named limiter in the diagnostic message', function (): void {
    app(RateLimiter::class)->for('reports', static fn () => Limit::perMinute(30));

    /** @var Router $router */
    $router = app('router');
    $router->get('api/registered-throttle', [FormController::class, 'index'])->middleware('throttle:reports');

    bindStubEngine();
    $result = generateDocument();

    $messages = array_map(static fn ($d): string => $d->message, $result->diagnostics);
    expect(implode("\n", $messages))->toContain('is registered but its limit is defined by a closure');
});

it('folds a registered named limiter to concrete numbers with no diagnostic', function (): void {
    // The idiomatic Laravel-11 default shape: an arrow closure partitioned by ip.
    $limiter = fn (Request $request) => Limit::perMinute(30)->by($request->ip());
    app(RateLimiter::class)->for('reports', $limiter);

    /** @var Router $router */
    $router = app('router');
    $router->get('api/folded-throttle', [FormController::class, 'index'])->middleware('throttle:reports');

    // Script the engine's closure trace for THIS limiter's ref (file::{closure}, the symbol the
    // extension builds from ReflectionFunction) so the fold runs end-to-end in-process. The real
    // engine folding an arrow limiter is proven separately by the fixture-group test.
    $reflection = new ReflectionFunction($limiter);
    $symbol = $reflection->getFileName().'::{closure}';
    $script = static function (TraceVisitor $visitor): void {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()
            ->parse("<?php\nreturn \\Illuminate\\Cache\\RateLimiting\\Limit::perMinute(30)->by('ip');\n") ?? [];
        $statement = $ast[0] ?? null;
        if ($statement instanceof Node\Stmt\Return_ && $statement->expr !== null) {
            $visitor->enterNode($statement->expr, new StubTraceScope(new ClassT('Illuminate\\Cache\\RateLimiting\\Limit')));
        }
    };

    app()->instance(TypeEngine::class, new StubTypeEngine(traces: [$symbol => $script]));
    $result = generateDocument();
    $operation = $result->document->toArray()['paths']['/api/folded-throttle']['get'] ?? [];

    $headers = $operation['responses']['429']['headers'] ?? [];
    expect($headers['X-RateLimit-Limit']['schema'])->toBe(['type' => 'integer', 'example' => 30])
        ->and($headers['Retry-After']['schema'])->toBe(['type' => 'integer', 'example' => 60]);

    $codes = array_map(static fn ($d): string => $d->code, $result->diagnostics);
    expect($codes)->not->toContain('rate-limit.dynamic-limit');
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
