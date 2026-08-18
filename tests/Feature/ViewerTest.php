<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Runtime\DocumentCache;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
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
    app(DocumentCache::class)->put('default', '{"cached":true}');
    expect($this->get('/docs/api.json')->assertOk()->getContent())->toBe('{"cached":true}');
});

it('opts into the CDN when viewer.cdn is true', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.cdn', true);
    Gate::before(static fn ($user = null): bool => true);

    $this->get('/docs/api')
        ->assertOk()
        ->assertSee('cdn.jsdelivr.net/npm/@scalar/api-reference', false);
});
