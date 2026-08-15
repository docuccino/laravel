<?php

declare(strict_types=1);

use Docuccino\Laravel\Tests\Fixtures\TagNames\Api\ReportController;
use Illuminate\Routing\Router;

/**
 * `Route::fallback()` registers a route on `{fallbackPlaceholder}` that answers whatever no other route
 * matched — and an API app registers it inside the prefix it documents, so it lands squarely in the
 * document. Published as an ordinary operation it becomes a phantom endpoint: a client generator emits
 * a method for `/api/{fallbackPlaceholder}`, a path nothing can call. OpenAPI has no "any unmatched
 * path" to say it with either, so there is nothing truthful to publish — the route is omitted and
 * REPORTED, because dropping it in silence would be its own defect.
 */
beforeEach(function (): void {
    $this->fallbackDocument = static function (callable $routes): array {
        $routes(app('router'));
        bindStubEngine();
        $result = generateDocument();

        return [$result->document->toArray(), $result->diagnostics];
    };

    // The shape an API app actually registers: the catch-all inside the prefix the document covers.
    $this->apiFallback = static function (Router $router, ?string $domain = null): void {
        $registrar = $domain === null ? $router->prefix('api') : $router->domain($domain)->prefix('api');
        $registrar->group(static function (Router $grouped): void {
            $grouped->fallback([ReportController::class, 'index']);
        });
    };
});

it('omits a fallback route and reports the omission', function (): void {
    [$document, $diagnostics] = ($this->fallbackDocument)(fn (Router $router) => ($this->apiFallback)($router));

    $reports = diagnosticsCoded($diagnostics, 'route.fallback-omitted');

    expect($document)->toHaveKey('paths')
        ->and($document['paths'])->not->toHaveKey('/api/{fallbackPlaceholder}')
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]->severity->value)->toBe('info')
        ->and($reports[0]->routeSignature)->toBe('GET /api/{fallbackPlaceholder}')
        ->and($reports[0]->message)->toContain('fallback route');
});

it('leaves every route beside the fallback exactly as it was', function (): void {
    // The locality half at document scale: the catch-all is dropped and nothing else moves — not the
    // ordinary route's node, not the paths it shares the document with.
    [$alone] = ($this->fallbackDocument)(static function (Router $router): void {
        $router->get('api/zz-reports', [ReportController::class, 'index']);
    });

    $this->refreshApplication();

    [$withFallback, $diagnostics] = ($this->fallbackDocument)(function (Router $router): void {
        $router->get('api/zz-reports', [ReportController::class, 'index']);
        ($this->apiFallback)($router);
    });

    expect($withFallback['paths'])->toHaveKey('/api/zz-reports')
        ->and($withFallback['paths'])->toEqual($alone['paths'])
        ->and(diagnosticsCoded($diagnostics, 'route.fallback-omitted'))->toHaveCount(1);
});

it('reports each fallback a document discovers, hosts included', function (): void {
    // An app may register one catch-all per host group. Each is its own omission, named by its own
    // signature, or the reader learns about one of them and not the other.
    [$document, $diagnostics] = ($this->fallbackDocument)(function (Router $router): void {
        ($this->apiFallback)($router);
        ($this->apiFallback)($router, 'admin.example.com');
    });

    $signatures = array_map(
        static fn ($diagnostic): ?string => $diagnostic->routeSignature,
        diagnosticsCoded($diagnostics, 'route.fallback-omitted'),
    );

    expect($document)->toHaveKey('paths')
        ->and($document['paths'])->not->toHaveKey('/api/{fallbackPlaceholder}')
        ->and($signatures)->toBe([
            'GET /api/{fallbackPlaceholder}',
            'GET admin.example.com/api/{fallbackPlaceholder}',
        ]);
});

it('says nothing about a fallback the document already excluded', function (): void {
    // An omission the author asked for is not news. An excluded route never reaches discovery, so it is
    // not reported as dropped on top of being filtered.
    config()->set('docuccino.documents.default.routes.exclude', ['api/{fallbackPlaceholder}']);

    [$document, $diagnostics] = ($this->fallbackDocument)(function (Router $router): void {
        $router->get('api/zz-reports', [ReportController::class, 'index']);
        ($this->apiFallback)($router);
    });

    expect($document['paths'])->toHaveKey('/api/zz-reports')
        ->and(diagnosticsCoded($diagnostics, 'route.fallback-omitted'))->toBeEmpty();
});
