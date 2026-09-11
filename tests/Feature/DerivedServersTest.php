<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Laravel\Config\BuildConfig;
use Docuccino\Laravel\Config\DerivedServers;
use Docuccino\Laravel\Support\MachineDependentValue;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\URL;
use Workbench\App\Http\Controllers\FormController;

/**
 * A document that declares no servers is the default every application starts on, and until it names
 * one an OpenAPI consumer is told nothing about where the API lives. The application already answered
 * that question — `app.url` is what the framework builds every absolute URL from — so a build that
 * leaves `servers` out has withheld a fact it holds.
 *
 * It only holds it when the URL is reachable, which is why the rest of the suite's goldens still
 * publish no servers: testbench runs on the framework's shipped `http://localhost`, and a document
 * naming that would send every generated client at whichever machine ran the build. Both halves are
 * pinned here, on one route set, because the interesting claim is the DIFFERENCE between them.
 */
function derivedServerRoutes(): callable
{
    return static function (Router $router): void {
        $router->get('api/forms', [FormController::class, 'index']);
        // A host-bound operation takes its scheme, port and base path from the document's server, so
        // this is where a derived one first reaches an operation rather than only the root array.
        $router->domain('admin.acme.com')->get('api/moderation', [FormController::class, 'index']);
    };
}

afterEach(function (): void {
    $path = app(BuildConfig::class)->raw('cache.path');
    if (is_string($path)) {
        removeFragmentCacheDir($path);
    }
});

/**
 * The harness pins `app.url`, and this is the row that says so out loud. Testbench reads that key from
 * the ambient `APP_URL`, this build publishes it into `servers`, and the framework builds every
 * absolute URL from it — so without the pin every golden in the suite asserts a value the machine
 * running it exported, and a developer or a CI runner with `APP_URL` set to a real API gets a screen
 * of unexplained red. A test may read the host; it may not assert what the host decides
 * (docs/testing.md §Standards).
 *
 * The pin is `phpunit.xml`'s `<env name="APP_URL" force="true">`, which is why the assertion is on the
 * ENVIRONMENT and not only on the config: a config-level pin lands after the console request root is
 * built from the raw value, so `URL::to()` would still answer with the host's. Remove the pin and this
 * reads `false` on a clean machine and the exported value on a machine that has one — neither is this.
 */
it('pins the application URL a build reads, whatever the environment exports', function (): void {
    expect(getenv('APP_URL'))->toBe('http://localhost')
        ->and(env('APP_URL'))->toBe('http://localhost')
        ->and(config('app.url'))->toBe('http://localhost')
        ->and(DerivedServers::for(app(ConfigRepository::class)))->toBe([])
        // The other consumer, and the one a config-level pin missed.
        ->and(URL::to('/docs/api'))->toBe('http://localhost/docs/api');
});

it('publishes the application URL as the document server when no servers are configured', function (): void {
    config()->set('app.url', 'https://api.acme.com');

    $document = emittedArray(localityBuild(derivedServerRoutes()));

    expect($document['servers'])->toBe([['url' => 'https://api.acme.com']]);
});

it('publishes no servers when the application URL names the build machine', function (): void {
    // The framework's own shipped default, which is what an application that never set APP_URL has.
    config()->set('app.url', 'http://localhost');

    $result = localityBuild(derivedServerRoutes());

    // Not a warning either. The population is every local build of every application, and there is
    // nothing to do in almost all of them — OpenAPI reads an absent `servers` as the origin the
    // document is served from, so a viewer and a client both keep working.
    expect(emittedArray($result))->not->toHaveKey('servers')
        ->and(diagnosticsCoded($result->diagnostics, MachineDependentValue::CODE))->toBe([]);
});

/**
 * The population the localhost row above does not stand in: a build that runs inside a private
 * network — a container, a CI runner, a staging box behind a VPN — where `app.url` names a host that
 * IS reachable by more than the build machine, and by nobody the exported document is handed to. The
 * document must publish no servers there for the same reason, and the golden byte-locks the whole of
 * it, because the host-bound operation has no root server left to inherit a scheme and base path
 * from and that answer is published too.
 */
it('publishes no servers when the application URL names a host only its own network can reach', function (): void {
    config()->set('app.url', 'http://192.168.1.50:8000');

    $result = localityBuild(derivedServerRoutes());

    // Still no diagnostic. The published-value rule does not report a LAN address on purpose — it
    // would warn every application documented from inside a private network — and there is nothing
    // for the reader of one to do that omitting has not already done.
    expect(emittedArray($result))->not->toHaveKey('servers')
        ->and(diagnosticsCoded($result->diagnostics, MachineDependentValue::CODE))->toBe([]);

    assertGolden('workbench-private-servers.uir.json', (new UirEmitter)->emit($result->document));
});

/**
 * The configured value wins outright: deriving over the top of an author who wrote the key would
 * publish a host they deliberately did not choose.
 */
it('leaves a configured servers array alone', function (): void {
    config()->set('app.url', 'https://api.acme.com');

    $result = generateDocument(static function (array $raw): array {
        $raw['servers'] = [['url' => 'https://edge.acme.com', 'description' => 'Edge']];

        return $raw;
    });

    expect(emittedArray($result)['servers'])->toBe([['url' => 'https://edge.acme.com', 'description' => 'Edge']]);
});

/**
 * A host-bound route publishes an operation-level `servers` that REPLACES the root array, so it has to
 * carry everything the root entry did but the host. Before the default existed there was nothing to
 * inherit and the extension fell back to a bare `https://`; now the document has a server, the
 * inheritance rule has to apply to a derived one exactly as to a written one, or an application that
 * writes no config gets a different answer from one that writes its own URL.
 */
it('lets a host-bound operation inherit the derived scheme and base path', function (): void {
    config()->set('app.url', 'http://api.acme.com/v2');

    $document = emittedArray(localityBuild(derivedServerRoutes()));

    expect($document['servers'])->toBe([['url' => 'http://api.acme.com/v2']])
        ->and($document['paths']['/api/moderation']['get']['servers'])->toBe([['url' => 'http://admin.acme.com/v2']]);
});

it('serves a warm build the same bytes and diagnostics as a cold one', function (): void {
    config()->set('app.url', 'https://api.acme.com');

    $warm = assertWarmEqualsCold(derivedServerRoutes(), derivedServerRoutes());

    assertGolden('workbench-derived-servers.uir.json', (new UirEmitter)->emit($warm->document));
});

/**
 * The `configHash` an emitted document publishes.
 *
 * @param  array<string, mixed>  $document
 */
function derivedServerConfigHash(array $document): string
{
    /** @var array{document: array{configHash: string}} $extension */
    $extension = $document['x-docuccino'];

    return $extension['document']['configHash'];
}

/**
 * The derived entry is part of what shaped the document, so the fingerprint the document publishes has
 * to move with it — a `configHash` that stayed put across two builds publishing different servers
 * would tell a reader comparing artifacts that nothing about the configuration changed. It is also
 * what retires the operation fragment a host-bound `servers` was built into.
 */
it('folds the derived server into the published configuration fingerprint', function (): void {
    config()->set('app.url', 'https://api.acme.com');
    $derived = emittedArray(localityBuild(derivedServerRoutes()));

    config()->set('app.url', 'https://other.acme.com');
    $other = emittedArray(localityBuild(derivedServerRoutes()));

    config()->set('app.url', 'http://localhost');
    $none = emittedArray(localityBuild(derivedServerRoutes()));

    expect(derivedServerConfigHash($derived))->not->toBe(derivedServerConfigHash($other))
        ->and(derivedServerConfigHash($derived))->not->toBe(derivedServerConfigHash($none));
});
