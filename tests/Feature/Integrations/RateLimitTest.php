<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
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
function throttledOperation(string $path): array
{
    bindStubEngine();
    $document = generateDocument()->document->toArray();

    return $document['paths']['/'.$path]['get'] ?? [];
}

it('adds a 429 with Retry-After + X-RateLimit-* headers for a numeric throttle', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/throttled', [FormController::class, 'index'])->middleware('throttle:60,1');

    $operation = throttledOperation('api/throttled');

    expect($operation['responses'])->toHaveKey('429');
    $response = $operation['responses']['429'];
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
    $operation = throttledOperation('api/forms');

    expect($operation['responses'] ?? [])->not->toHaveKey('429');
});
