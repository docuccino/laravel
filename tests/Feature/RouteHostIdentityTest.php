<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Support\MachineDependentValue;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Admin\ReportController as AdminReportController;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Api\ReportController as ApiReportController;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/**
 * `Route::domain('admin.example.com')->group(...)` is ordinary Laravel, and `{tenant}.example.com` is
 * how a multi-tenant app routes. The host is therefore part of what makes one route a different route:
 * two routes on one method and URI but different hosts are two operations, and every key that tells
 * routes apart — the discovery dedup, the fragment cache, the operation identity — has to see it or one
 * sibling silently answers for the other.
 *
 * OpenAPI has no per-operation host, so the host reaches a reader as an operation-level `servers`
 * entry. What OpenAPI also has no room for is two operations on one path and method, and that case is
 * REPORTED rather than resolved by whichever route happened to register first.
 */
beforeEach(function (): void {
    $this->hostedDocument = static function (callable $routes, ?callable $mutateConfig = null): array {
        $routes(app('router'));
        bindStubEngine();
        $result = generateDocument($mutateConfig);

        return [$result->document->toArray(), $result->diagnostics, $result->document];
    };

    $this->cacheInto = static function (string $slug): string {
        $dir = sys_get_temp_dir().'/docuccino-'.$slug.'-'.uniqid('', true);
        config()->set('docuccino.cache.enabled', true);
        config()->set('docuccino.cache.path', $dir);

        return $dir;
    };
});

afterEach(function (): void {
    $path = config('docuccino.cache.path');
    if (is_string($path) && is_dir($path)) {
        array_map('unlink', glob($path.'/*') ?: []);
        @unlink($path.'/.gitignore');
        @rmdir($path);
    }
});

it('documents one of two hosts on a URI and reports the one OpenAPI has no slot for', function (): void {
    [$document, $diagnostics] = ($this->hostedDocument)(static function ($router): void {
        $router->domain('a.example.com')->get('api/zz-hosts', [ApiReportController::class, 'index']);
        $router->domain('b.example.com')->get('api/zz-hosts', [AdminReportController::class, 'index']);
    });

    $collisions = array_values(array_filter(
        $diagnostics,
        fn (Diagnostic $d): bool => $d->code === 'paths.operation-collision',
    ));

    expect($document['paths']['/api/zz-hosts'])->toHaveKey('get')
        // The survivor is coherent: the host it names is the host whose controller built it.
        ->and($document['paths']['/api/zz-hosts']['get'])->toHaveKey('servers')
        ->and($document['paths']['/api/zz-hosts']['get']['servers'][0]['url'])->toBe('https://a.example.com')
        ->and($collisions)->toHaveCount(1)
        ->and($collisions[0]->severity->value)->toBe('error')
        ->and($collisions[0]->routeSignature)->toBe('GET b.example.com/api/zz-hosts');
});

it('keeps the same host of the pair whichever order the routes were registered in', function (): void {
    // A minted answer is a function of the routes, never of the order the router met them. Before the
    // fix the second registration won by overwriting the first, in silence.
    [$forwards] = ($this->hostedDocument)(static function ($router): void {
        $router->domain('a.example.com')->get('api/zz-hosts', [ApiReportController::class, 'index']);
        $router->domain('b.example.com')->get('api/zz-hosts', [AdminReportController::class, 'index']);
    });

    $this->refreshApplication();

    [$backwards] = ($this->hostedDocument)(static function ($router): void {
        $router->domain('b.example.com')->get('api/zz-hosts', [AdminReportController::class, 'index']);
        $router->domain('a.example.com')->get('api/zz-hosts', [ApiReportController::class, 'index']);
    });

    expect($backwards['paths']['/api/zz-hosts'])->toEqual($forwards['paths']['/api/zz-hosts']);
});

it('lets a host-less route keep the URI when a hosted sibling arrives, byte for byte', function (): void {
    // Locality: adding a route must not move an unrelated route's emitted representation — and the
    // identity of every operation ever emitted for a host-less route has to survive this change.
    [$alone] = ($this->hostedDocument)(static function ($router): void {
        $router->get('api/zz-hosts', [ApiReportController::class, 'index']);
    });

    $this->refreshApplication();

    [$withSibling, $diagnostics] = ($this->hostedDocument)(static function ($router): void {
        $router->get('api/zz-hosts', [ApiReportController::class, 'index']);
        $router->domain('admin.example.com')->get('api/zz-hosts', [AdminReportController::class, 'index']);
    });

    expect($withSibling['paths']['/api/zz-hosts']['get'])->toEqual($alone['paths']['/api/zz-hosts']['get'])
        ->and($withSibling['paths']['/api/zz-hosts']['get'])->not->toHaveKey('servers')
        // …and the sibling that could not be emitted is still reported, not lost quietly.
        ->and(diagnosticsCoded($diagnostics, 'paths.operation-collision'))->toHaveCount(1);
});

it('names the host a route is bound to in operation-level servers', function (): void {
    [$document] = ($this->hostedDocument)(static function ($router): void {
        $router->domain('admin.example.com')->get('api/zz-admin', [AdminReportController::class, 'index']);
        $router->get('api/zz-open', [ApiReportController::class, 'index']);
    });

    expect($document['paths']['/api/zz-admin']['get'])->toHaveKey('servers')
        ->and($document['paths']['/api/zz-admin']['get']['servers'])
        ->toBe([['url' => 'https://admin.example.com']])
        // The negative half: a route that answers on every host must not gain a host it never had.
        ->and($document['paths']['/api/zz-open']['get'])->not->toHaveKey('servers');
});

it('turns a templated host into server variables, optional marker dropped', function (): void {
    [$document] = ($this->hostedDocument)(static function ($router): void {
        $router->domain('{tenant}.{region?}.example.com')->get('api/zz-tenant', [ApiReportController::class, 'index']);
    });

    $server = $document['paths']['/api/zz-tenant']['get']['servers'][0] ?? [];

    expect($document['paths']['/api/zz-tenant']['get'])->toHaveKey('servers')
        ->and($server['url'])->toBe('https://{tenant}.{region}.example.com')
        ->and($server)->toHaveKey('variables')
        ->and(array_keys($server['variables']))->toBe(['tenant', 'region'])
        ->and($server['variables']['tenant']['default'])->toBe('tenant')
        ->and($server['variables']['region']['default'])->toBe('region');
});

/**
 * Binding a host swaps the HOST out of the document's server URL and nothing else. Operation-level
 * `servers` overrides the root array outright in every OAS version, so anything dropped here is
 * dropped for that operation: a base path left behind sends a generated client to a URL the API does
 * not serve, and it is still valid OpenAPI while it does it.
 */
it('swaps only the host out of the document server URL, keeping its scheme, port and path', function (array $servers, string $expected): void {
    [$document] = ($this->hostedDocument)(
        static function ($router): void {
            $router->domain('admin.example.com')->get('api/zz-scheme', [AdminReportController::class, 'index']);
        },
        static function (array $raw) use ($servers): array {
            $raw['servers'] = $servers;

            return $raw;
        },
    );

    expect($document['paths']['/api/zz-scheme']['get'])->toHaveKey('servers')
        ->and($document['paths']['/api/zz-scheme']['get']['servers'][0]['url'])->toBe($expected);
})->with([
    'none configured' => [[], 'https://admin.example.com'],
    'an https server' => [[['url' => 'https://api.example.com']], 'https://admin.example.com'],
    // A local http app documents its hosted routes over http rather than a confident, wrong https.
    'a local http server on a port' => [[['url' => 'http://localhost:8000']], 'http://admin.example.com:8000'],
    'a base path' => [[['url' => 'https://api.example.com/v1']], 'https://admin.example.com/v1'],
    'a deep base path' => [[['url' => 'https://api.example.com/api/v2']], 'https://admin.example.com/api/v2'],
    'a port and a base path together' => [[['url' => 'https://api.example.com:8443/v1']], 'https://admin.example.com:8443/v1'],
    // `https://host/` is the empty base path spelled out, not a path segment to carry.
    'a trailing slash only' => [[['url' => 'https://api.example.com/']], 'https://admin.example.com'],
    'a relative server, then an absolute one' => [[['url' => '/api'], ['url' => 'http://localhost/v1']], 'http://admin.example.com/v1'],
    'a relative server only' => [[['url' => '/api']], 'https://admin.example.com'],
    'a url that is not a string' => [[['url' => ['https://api.example.com']]], 'https://admin.example.com'],
    'a server with no url at all' => [[['description' => 'Production']], 'https://admin.example.com'],
]);

it('carries over a variable the inherited base path still names, and drops the rest', function (): void {
    // An operation-level server overrides the root one, so it has to define every variable in its own
    // URL — the document's definition of `{version}` does not reach it.
    [$document] = ($this->hostedDocument)(
        static function ($router): void {
            $router->domain('{tenant}.example.com')->get('api/zz-inherit', [ApiReportController::class, 'index']);
        },
        static function (array $raw): array {
            $raw['servers'] = [[
                'url' => 'https://api.example.com/{version}',
                'variables' => [
                    'version' => ['default' => 'v1', 'enum' => ['v1', 'v2']],
                    'unused' => ['default' => 'nothing names me'],
                ],
            ]];

            return $raw;
        },
    );

    $server = $document['paths']['/api/zz-inherit']['get']['servers'][0] ?? [];

    expect($server['url'])->toBe('https://{tenant}.example.com/{version}')
        ->and(array_keys($server['variables']))->toBe(['tenant', 'version'])
        ->and($server['variables']['version'])->toBe(['default' => 'v1', 'enum' => ['v1', 'v2']]);
});

/**
 * `Route::domain(config('app.admin_domain'))` is ordinary Laravel, and the value behind it is env. The
 * published server URL is then exactly as unreachable as an unpinned `app.url`, so it gets the same
 * rule — a host-bound URL was the one path around it.
 */
it('warns when the host a route binds itself to names the build machine', function (): void {
    [, $diagnostics] = ($this->hostedDocument)(static function ($router): void {
        $router->domain('admin.myapp.test')->get('api/zz-local-host', [AdminReportController::class, 'index']);
        $router->domain('admin.example.com')->get('api/zz-public-host', [ApiReportController::class, 'index']);
    });

    $reports = diagnosticsCoded($diagnostics, MachineDependentValue::CODE);

    expect($reports)->toHaveCount(1)
        ->and($reports[0]->severity)->toBe(Severity::Warning)
        ->and($reports[0]->message)->toContain('https://admin.myapp.test')
        ->and($reports[0]->routeSignature)->toBe('GET admin.myapp.test/api/zz-local-host');
});

it('serves a warm build the same bytes and the same diagnostics as a cold one', function (): void {
    // Through `assertWarmEqualsCold()` rather than by building twice into one cache directory: nothing
    // in that shape checked the cache was written OR hit, so both builds could be cold and agree — the
    // test would stay green with the fragment cache entirely inert. The helper proves the cache file
    // exists and that the warm build reached the type engine strictly less often.
    $routes = static function (Router $router): void {
        $router->domain('a.example.com')->get('api/zz-hosts', [ApiReportController::class, 'index']);
        $router->domain('b.example.com')->get('api/zz-hosts', [AdminReportController::class, 'index']);
        $router->domain('{tenant}.example.com')->get('api/zz-tenant', [ApiReportController::class, 'index']);
    };

    $warm = assertWarmEqualsCold($routes, $routes);
    $document = $warm->document->toArray();

    // Equal diagnostics that are equally EMPTY would prove nothing, so the two facts this build is
    // supposed to carry are pinned outright.
    expect($document['paths']['/api/zz-tenant']['get'])->toHaveKey('servers')
        ->and(diagnosticsCoded($warm->diagnostics, 'paths.operation-collision'))->toHaveCount(1);
});

it('never serves one host the fragment cached for its sibling on the same URI', function (): void {
    // The correctness half of the cache key. The two routes agree on method, URI, name, middleware and
    // action — a tenant group and a public one pointing at one controller is exactly that shape — so
    // the host is the ONLY thing between them. Leave it out of the key and the second build is served
    // the first host's fragment: a document describing an endpoint that is not there.
    $dir = ($this->cacheInto)('hosts-swap');

    [$first] = ($this->hostedDocument)(static function ($router): void {
        $router->domain('a.example.com')->get('api/zz-swap', [ApiReportController::class, 'index']);
    });

    // Without this the test proves nothing: an unwritten cache cannot serve the wrong fragment.
    expect(glob($dir.'/*.json') ?: [])->not->toBeEmpty();

    // Same app and the same warm cache, with only the other host's route registered.
    app('router')->setRoutes(new RouteCollection);
    [$second] = ($this->hostedDocument)(static function ($router): void {
        $router->domain('b.example.com')->get('api/zz-swap', [ApiReportController::class, 'index']);
    });

    expect($first['paths']['/api/zz-swap']['get'])->toHaveKey('servers')
        ->and($first['paths']['/api/zz-swap']['get']['servers'][0]['url'])->toBe('https://a.example.com')
        ->and($second['paths']['/api/zz-swap']['get'])->toHaveKey('servers')
        ->and($second['paths']['/api/zz-swap']['get']['servers'][0]['url'])->toBe('https://b.example.com')
        // Two hosts are two operations, so the identities the diff pairs on must differ too.
        ->and($second['paths']['/api/zz-swap']['get']['x-docuccino']['id'])
        ->not->toBe($first['paths']['/api/zz-swap']['get']['x-docuccino']['id']);
});
