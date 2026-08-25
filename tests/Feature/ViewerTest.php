<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Context\ViewerContext;
use Docuccino\Core\Extensions\Contracts\Viewer;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Runtime\DocumentCache;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Docuccino\Laravel\Viewer\ViewerDrivers;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * The runtime viewer endpoints: Gate-guarded HTML plus `.json`, and a locally bundled Scalar asset — no
 * runtime CDN by default.
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
});

it('denies access by default outside the local environment', function (): void {
    // Testbench runs as the "testing" environment and no gate is configured, so access is denied.
    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
    $this->get('/docs/api/assets/scalar.js')->assertForbidden();
});

it('allows access when the configured gate passes', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);

    $this->get('/docs/api')->assertOk();
});

it('serves the Scalar HTML referencing the spec URL and the locally bundled asset', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);

    $response = $this->get('/docs/api');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('id="api-reference"', false)
        // The spec URL points at the document's .json endpoint…
        ->assertSee('data-url="'.url('/docs/api.json').'"', false)
        // …and the Scalar script is the local asset, never a CDN.
        ->assertSee('src="'.url('/docs/api/assets/scalar.js').'"', false)
        ->assertDontSee('cdn.jsdelivr.net', false);
});

it('serves the generated OpenAPI JSON', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);

    $response = $this->get('/docs/api.json');

    $response->assertOk()->assertHeader('Content-Type', 'application/json');
    expect($response->getContent())
        ->toBe(file_get_contents(dirname(__DIR__).'/Fixtures/golden/workbench.openapi.json'));
});

// The tag forest reaches the page in the form the bundled viewers actually render — `x-tagGroups` —
// in the newest format and the 3.1 downlevel alike (the downlevel drops only the `parent` member).
it('projects a tag hierarchy as x-tagGroups through the spec endpoint', function (string $driver): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.driver', $driver);
    config()->set('docuccino.documents.default.tags.definitions', [
        ['name' => 'Billing'],
        ['name' => 'Invoices', 'parent' => 'Billing'],
    ]);
    Gate::before(static fn ($user = null): bool => true);

    $json = json_decode((string) $this->get('/docs/api.json')->getContent(), true);

    expect($json['x-tagGroups'])->toBe([['name' => 'Billing', 'tags' => ['Billing', 'Invoices']]]);
})->with(['scalar', 'redoc']);

// The absence pin: an unset `viewer.configuration` must publish no attribute at all, not an empty
// object — a viewer handed `{}` can read it as an instruction to reset its own defaults.
it('publishes no data-configuration when viewer.configuration is unset', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);

    $this->get('/docs/api')
        ->assertOk()
        ->assertSee('id="api-reference"', false)
        ->assertDontSee('data-configuration', false);
});

it('passes viewer.configuration to Scalar as its data-configuration attribute', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.configuration', ['theme' => 'kepler', 'hideModels' => true]);
    Gate::before(static fn ($user = null): bool => true);

    $this->get('/docs/api')
        ->assertOk()
        ->assertSee('data-configuration="{&quot;theme&quot;:&quot;kepler&quot;,&quot;hideModels&quot;:true}"', false);
});

// The bundled Redoc implements 3.1 (a 3.2 document is merely tolerated, aliased to 3.1), so its spec
// endpoint serves the downlevel — while the default driver keeps the newest format.
it('serves each driver the OpenAPI version it implements', function (string $driver, string $version): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.driver', $driver);
    Gate::before(static fn ($user = null): bool => true);

    $json = json_decode((string) $this->get('/docs/api.json')->getContent(), true);

    expect($json['openapi'])->toStartWith($version);
})->with([
    'redoc' => ['redoc', '3.1'],
    'scalar' => ['scalar', '3.2'],
]);

it('serves the bundled Scalar asset to an authorized viewer', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);

    $this->get('/docs/api/assets/scalar.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript')
        // Versioned by the package release, so it's served with a long-lived immutable cache. Symfony's
        // header bag re-serialises Cache-Control directives alphabetically.
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
        ->assertSee('api-reference', false);
});

it('denies the spec with a 403 when the configured gate rejects', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => false);

    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
});

it('denies the Scalar asset with a 403 when the configured gate rejects', function (): void {
    // The asset goes through the same gate as the page that loads it — an unauthorized viewer gets
    // nothing, not even the 3.6 MB bundle.
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => false);

    $this->get('/docs/api/assets/scalar.js')->assertForbidden();
});

it('allows the viewer in the local environment without a gate', function (): void {
    app()['env'] = 'local';

    $this->get('/docs/api')->assertOk();
    $this->get('/docs/api.json')->assertOk();
});

it('404s a viewer route whose document is no longer configured', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);

    // The route was registered at boot but the document has since gone, so hasDocument() is false.
    config()->set('docuccino.documents', []);

    $this->get('/docs/api.json')->assertNotFound();
});

it('serves source=artifact, re-emitting a UIR artifact as OpenAPI', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.source', 'artifact');
    Gate::before(static fn ($user = null): bool => true);

    // Write a UIR artifact (carries the `uir` field + x-docuccino provenance) to the export path.
    $artifact = sys_get_temp_dir().'/docuccino-artifact-'.uniqid().'.json';
    file_put_contents($artifact, (new UirEmitter)->emit(
        UirDocument::fromArray(
            app(DocumentGenerator::class)->generate(
                app(DocumentConfigFactory::class)->make('default', (array) config('docuccino.documents.default'), 'skeleton'),
                app(TypeEngine::class),
            )->document->toArray(),
        ),
    ));
    config()->set('docuccino.documents.default.export.path', $artifact);

    $body = $this->get('/docs/api.json')->assertOk()->getContent();

    // Re-emitted through the OpenAPI emitter: it's OAS (no `uir` key) and leaks no internal x-docuccino
    // provenance to the browser.
    expect($body)->toContain('"openapi"')
        ->and($body)->not->toContain('"uir"')
        ->and($body)->not->toContain('x-docuccino');

    @unlink($artifact);
});

it('serves an artifact the empty objects it holds, so the viewer and the export agree', function (): void {
    // The same document, two answers. `source=artifact` re-emits what `docuccino:export` shipped, and an
    // associative decode on the way in reads `example: {}` back as `[]` — so the browser was shown a
    // free-form example that lies about its shape while the file beside it was right. `{}` and `[]` are
    // one PHP array, so this is invisible to every assertion that compares decoded documents.
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.source', 'artifact');
    Gate::before(static fn ($user = null): bool => true);

    $document = UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'Artifact', 'version' => '1.0.0'],
        'paths' => ['/things' => ['get' => ['responses' => ['200' => [
            'description' => 'OK',
            'content' => ['application/json' => [
                'schema' => ['type' => 'object', 'additionalProperties' => true],
                'example' => new stdClass,
            ]],
        ]]]]],
    ]);

    $artifact = sys_get_temp_dir().'/docuccino-empty-object-'.uniqid().'.json';
    file_put_contents($artifact, (new UirEmitter)->emit($document));
    config()->set('docuccino.documents.default.export.path', $artifact);

    $body = $this->get('/docs/api.json')->assertOk()->getContent();

    $config = app(DocumentConfigFactory::class)->make('default', (array) config('docuccino.documents.default'), 'skeleton');

    expect($body)->toContain('"example": {}')
        ->and($body)->not->toContain('"example": []')
        // …and byte-for-byte what the document never written to disk emits, which is the whole claim:
        // the round trip through the artifact costs the viewer nothing.
        ->and($body)->toBe(app(ViewerDrivers::class)->emitFor($config, $document));

    @unlink($artifact);
});

it('warns rather than building when the artifact is missing', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.source', 'artifact');
    $missing = sys_get_temp_dir().'/does-not-exist-'.uniqid().'.json';
    config()->set('docuccino.documents.default.export.path', $missing);
    Gate::before(static fn ($user = null): bool => true);

    // `artifact` is chosen so no request ever re-analyses, so the empty body stands — but the log now
    // names the file and the command that writes it, which is what "the docs page is empty" needed.
    Log::shouldReceive('warning')->once()->withArgs(
        static fn (string $message): bool => str_contains($message, $missing) && str_contains($message, 'docuccino:export'),
    );

    expect($this->get('/docs/api.json')->assertOk()->getContent())->toBe('');
});

it('picks the best servable target whatever order the list is written in', function (array $targets): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.source', 'artifact');
    Gate::before(static fn ($user = null): bool => true);

    $dir = sys_get_temp_dir().'/docuccino-viewer-'.uniqid('', true);
    @mkdir($dir, 0777, true);

    // Each target gets a body naming itself, so the served bytes say which one was chosen.
    $configured = [];
    foreach ($targets as $format) {
        $path = $dir.'/'.$format.'.json';
        file_put_contents($path, sprintf('{"openapi":"served-%s"}', $format));
        $configured[] = ['format' => $format, 'path' => $path];
    }
    config()->set('docuccino.documents.default.export', ['targets' => $configured]);

    // 3.2 is the most faithful thing the viewer can serve, so it wins regardless of list order.
    expect($this->get('/docs/api.json')->assertOk()->getContent())->toContain('served-openapi-3.2');
})->with([
    '3.2 first' => [['openapi-3.2', 'openapi-3.1', 'uir']],
    '3.2 last' => [['uir', 'openapi-3.1', 'openapi-3.2']],
    '3.2 in the middle' => [['openapi-3.1', 'openapi-3.2', 'uir']],
]);

it('skips a YAML target rather than serving YAML as application/json', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.source', 'artifact');
    Gate::before(static fn ($user = null): bool => true);

    $dir = sys_get_temp_dir().'/docuccino-viewer-yaml-'.uniqid('', true);
    @mkdir($dir, 0777, true);
    file_put_contents($dir.'/openapi.yaml', "openapi: 3.2.0\n");
    file_put_contents($dir.'/api.uir.json', '{"uir":"1.0.0","openapi":"3.2.0","info":{"title":"T","version":"1"},"paths":{}}');

    config()->set('docuccino.documents.default.export', ['targets' => [
        ['format' => 'openapi-3.2', 'path' => $dir.'/openapi.yaml'],
        ['format' => 'uir', 'path' => $dir.'/api.uir.json'],
    ]]);

    // The 3.2 target ranks higher but is YAML, which the browser cannot read under this content type.
    $body = $this->get('/docs/api.json')->assertOk()->assertHeader('Content-Type', 'application/json')->getContent();

    expect($body)->not->toContain('openapi: 3.2.0')
        ->and($body)->toContain('"openapi"');
});

it('generates rather than serving bytes the viewer cannot read', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.source', 'artifact');
    Gate::before(static fn ($user = null): bool => true);

    // Every target is YAML, so nothing here is servable — generating beats an unreadable body.
    config()->set('docuccino.documents.default.export', ['targets' => [
        ['format' => 'openapi-3.2', 'path' => sys_get_temp_dir().'/nope-'.uniqid().'.yaml'],
    ]]);

    Log::shouldReceive('warning')->once()->withArgs(
        static fn (string $message): bool => str_contains($message, 'no export target holds JSON'),
    );

    expect($this->get('/docs/api.json')->assertOk()->getContent())->toContain('/api/forms');
});

it('serves source=cache when warm, and falls back to generate when cold', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.source', 'cache');
    Gate::before(static fn ($user = null): bool => true);

    // A cold cache falls back to generating a real spec rather than serving an empty body.
    expect($this->get('/docs/api.json')->assertOk()->getContent())->toContain('/api/forms');

    // Warm the cache with a sentinel and confirm it is served verbatim.
    app(DocumentCache::class)->put('default', '{"cached":true}', 'openapi-3.2');
    expect($this->get('/docs/api.json')->assertOk()->getContent())->toBe('{"cached":true}');
});

it('re-emits rather than serving a payload cached for the format another driver was on', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.source', 'cache');
    Gate::before(static fn ($user = null): bool => true);

    // Warmed while the document was on Scalar, which is served 3.2.
    app(DocumentCache::class)->put('default', '{"cached":"3.2"}', 'openapi-3.2');
    expect($this->get('/docs/api.json')->assertOk()->getContent())->toBe('{"cached":"3.2"}');

    // Switching to Redoc changes the version the endpoint serves, so the warm entry no longer
    // answers: without this the endpoint would serve 3.2 bytes to a 3.1 viewer indefinitely.
    config()->set('docuccino.documents.default.viewer.driver', 'redoc');
    Log::shouldReceive('warning')->withAnyArgs();

    $served = $this->get('/docs/api.json')->assertOk()->getContent();

    expect($served)->not->toBe('{"cached":"3.2"}')
        ->and($served)->toContain('"openapi": "3.1.')
        ->and($served)->toContain('/api/forms');
});

it('opts into the CDN when viewer.cdn is true', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.cdn', true);
    Gate::before(static fn ($user = null): bool => true);

    $this->get('/docs/api')
        ->assertOk()
        ->assertSee('cdn.jsdelivr.net/npm/@scalar/api-reference', false);
});

/*
|--------------------------------------------------------------------------
| Drivers
|--------------------------------------------------------------------------
|
| `viewer.driver` picks which registered Viewer renders the page. Everything below runs over the whole
| shipped set — a seam proven on one driver is not proven — plus the unknown-name degradation.
|
*/

dataset('viewer drivers', [
    'scalar' => ['scalar', 'id="api-reference"', 'scalar', 'cdn.jsdelivr.net/npm/@scalar/api-reference@1'],
    'redoc' => ['redoc', '<redoc spec-url=', 'redoc', 'cdn.jsdelivr.net/npm/redoc@2/bundles/redoc.standalone.js'],
]);

it('renders the default page byte for byte', function (): void {
    // The page an app already serves is a contract with everyone who has it bookmarked, so making the
    // viewer pluggable may not move a byte of it.
    app()['env'] = 'local';

    $spec = url('/docs/api.json');
    $asset = url('/docs/api/assets/scalar.js');

    expect($this->get('/docs/api')->assertOk()->getContent())->toBe(<<<HTML
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>API Documentation</title>
        </head>
        <body>
            <script id="api-reference" data-url="{$spec}"></script>
            <script src="{$asset}"></script>
        </body>
        </html>
        HTML);
});

it('renders every shipped driver from its own locally served asset', function (string $driver, string $marker, string $asset, string $cdn): void {
    config()->set('docuccino.documents.default.viewer.driver', $driver);
    app()['env'] = 'local';

    $this->get('/docs/api')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee($marker, false)
        // Whichever driver renders, it points at the document's own .json endpoint…
        ->assertSee(url('/docs/api.json'), false)
        // …and loads its script from this app, never a CDN.
        ->assertSee('src="'.url('/docs/api/assets/'.$asset.'.js').'"', false)
        ->assertDontSee('cdn.jsdelivr.net', false);

    $this->get('/docs/api/assets/'.$asset.'.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
})->with('viewer drivers');

it('opts every shipped driver into the CDN when viewer.cdn is true', function (string $driver, string $marker, string $asset, string $cdn): void {
    config()->set('docuccino.documents.default.viewer.driver', $driver);
    config()->set('docuccino.documents.default.viewer.cdn', true);
    app()['env'] = 'local';

    $this->get('/docs/api')
        ->assertOk()
        ->assertSee($cdn, false)
        ->assertDontSee(url('/docs/api/assets/'.$asset.'.js'), false);
})->with('viewer drivers');

it('gates every shipped driver and its asset', function (string $driver, string $marker, string $asset, string $cdn): void {
    // The security-relevant one: authorization runs in the controller, ahead of any driver, and nothing
    // else ever calls one — so no driver can be added that renders for someone the gate refuses.
    config()->set('docuccino.documents.default.viewer.driver', $driver);
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => false);

    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
    $this->get('/docs/api/assets/'.$asset.'.js')->assertForbidden();
})->with('viewer drivers');

it('serves only the assets the active driver publishes', function (): void {
    config()->set('docuccino.documents.default.viewer.driver', 'scalar');
    app()['env'] = 'local';

    $this->get('/docs/api/assets/scalar.js')->assertOk();
    $this->get('/docs/api/assets/redoc.js')->assertNotFound();

    config()->set('docuccino.documents.default.viewer.driver', 'redoc');

    $this->get('/docs/api/assets/redoc.js')->assertOk();
    $this->get('/docs/api/assets/scalar.js')->assertNotFound();
});

it('reaches no file the driver did not publish', function (string $name): void {
    app()['env'] = 'local';

    $this->get('/docs/api/assets/'.$name.'.js')->assertNotFound();
})->with([
    'an unregistered name' => ['swagger'],
    'a traversal' => ['..%2F..%2Fcomposer'],
    'a dotted path' => ['../../composer'],
]);

it('falls back to the default driver and says so when viewer.driver names nothing', function (): void {
    config()->set('docuccino.documents.default.viewer.driver', 'nope');
    app()['env'] = 'local';

    // A page whose whole job is to be readable degrades to the default rather than fataling, and the
    // log carries the diagnosis — including the names the app could have asked for.
    Log::shouldReceive('warning')->once()->withArgs(
        static fn (string $message): bool => str_contains($message, '"nope"') && str_contains($message, 'scalar, redoc'),
    );

    $this->get('/docs/api')->assertOk()->assertSee('id="api-reference"', false);
});

it('renders a driver a third party registered', function (): void {
    Docuccino::extend(new class implements Viewer
    {
        public function name(): string
        {
            return 'house-style';
        }

        public function render(ViewerContext $context): string
        {
            return '<h1>'.$context->config->key.'</h1>';
        }
    });

    config()->set('docuccino.documents.default.viewer.driver', 'house-style');
    app()['env'] = 'local';

    expect($this->get('/docs/api')->assertOk()->getContent())->toBe('<h1>default</h1>');
});

it('lets a registration replace a shipped driver under its own name', function (): void {
    Docuccino::extend(new class implements Viewer
    {
        public function name(): string
        {
            return 'scalar';
        }

        public function render(ViewerContext $context): string
        {
            return '<h1>rebranded</h1>';
        }
    });

    app()['env'] = 'local';

    // No `driver` key at all: the default name still resolves, now to the replacement.
    expect($this->get('/docs/api')->assertOk()->getContent())->toBe('<h1>rebranded</h1>');
});

it('gates a third-party driver like any other', function (): void {
    Docuccino::extend(new class implements Viewer
    {
        public function name(): string
        {
            return 'house-style';
        }

        public function render(ViewerContext $context): string
        {
            return '<h1>secret</h1>';
        }
    });

    config()->set('docuccino.documents.default.viewer.driver', 'house-style');
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => false);

    $this->get('/docs/api')->assertForbidden();
});

it('serves an empty page and warns when a driver renders something unservable', function (): void {
    Docuccino::extend(new class implements Viewer
    {
        public function name(): string
        {
            return 'broken';
        }

        /** @return array<string, string> */
        public function render(ViewerContext $context): array
        {
            return ['not' => 'html'];
        }
    });

    config()->set('docuccino.documents.default.viewer.driver', 'broken');
    app()['env'] = 'local';

    Log::shouldReceive('warning')->once()->withArgs(
        static fn (string $message): bool => str_contains($message, 'rendered a array'),
    );

    expect($this->get('/docs/api')->assertOk()->getContent())->toBe('');
});
