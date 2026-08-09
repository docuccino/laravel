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

/**
 * The runtime viewer endpoints (design §5 serving): Gate-guarded HTML + `.json` + a locally bundled
 * Scalar asset (no runtime CDN by default).
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
});

it('denies access by default outside the local environment', function (): void {
    // Testbench runs as the "testing" environment and no gate is configured, so access is denied.
    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
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
        // The spec URL points at the document's .json endpoint...
        ->assertSee('data-url="'.url('/docs/api.json').'"', false)
        // ...and the Scalar script is the local asset, never a CDN.
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

it('serves the bundled Scalar asset WITHOUT the gate (it is a static, non-sensitive script)', function (): void {
    // No gate configured and the environment is "testing", so /docs/api and /docs/api.json are
    // forbidden — but the asset is a static JS file with nothing sensitive, so it stays open
    // (Scalar must be able to load it). This is the intended, documented behaviour.
    $this->get('/docs/api')->assertForbidden();

    $this->get('/docs/api/assets/scalar.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript')
        // Versioned by the package release, so it is served with a long-lived immutable cache (N8).
        // Symfony's header bag re-serialises Cache-Control directives in alphabetical order.
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
        ->assertSee('api-reference', false);
});

it('denies the spec with a 403 when the configured gate rejects', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => false);

    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
});

it('allows the viewer in the local environment without a gate', function (): void {
    app()['env'] = 'local';

    $this->get('/docs/api')->assertOk();
    $this->get('/docs/api.json')->assertOk();
});

it('404s a viewer route whose document is no longer configured', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);

    // The route was registered at boot, but the document has since gone → hasDocument() is false.
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

    // Re-emitted through the OpenAPI emitter: it is OAS (no `uir` key) and carries no internal
    // x-docuccino provenance leaked to the browser (security L1).
    expect($body)->toContain('"openapi"')
        ->and($body)->not->toContain('"uir"')
        ->and($body)->not->toContain('x-docuccino');

    @unlink($artifact);
});

it('serves an empty body for source=artifact when the artifact is missing', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.source', 'artifact');
    config()->set('docuccino.documents.default.export.path', sys_get_temp_dir().'/does-not-exist-'.uniqid().'.json');
    Gate::before(static fn ($user = null): bool => true);

    expect($this->get('/docs/api.json')->assertOk()->getContent())->toBe('');
});

it('serves source=cache when warm, and falls back to generate when cold', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.source', 'cache');
    Gate::before(static fn ($user = null): bool => true);

    // Cold cache → F11 fallback generates a real spec rather than an empty body.
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
