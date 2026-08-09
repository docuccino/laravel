<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\UirEmitter;

/*
 * The narrative content layer end-to-end against the workbench content directory: the compiled
 * pages + nav tree land in the UIR, directives resolve to stable ids, OpenAPI strips the whole
 * layer, and content is a document-level cache input.
 */

/**
 * Point the test app at the workbench content tree the way the shipped config documents it — a `dir`
 * RELATIVE to the application base path — by basing the app on the adapter package, so the workbench
 * sits inside it exactly as `resources/docs/api` sits inside a real application.
 *
 * Configuring it relatively also exercises the project-root-relative page `source` prefix the compiler
 * promises, which nothing else covered. It is no longer load-bearing for the byte-locked golden:
 * `ConfigPaths` relativises any in-app absolute path before it can reach the emitted `configHash`
 * (proven in `ConfigPathsTest`) — but writing it the way the shipped config documents it stays the
 * honest fixture.
 */
function withWorkbenchContent(): callable
{
    app()->setBasePath(dirname(__DIR__, 2));

    return withContent('workbench/resources/docs/api');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function withContent(string $dir, array $overrides = []): callable
{
    return static function (array $raw) use ($dir, $overrides): array {
        $raw['content'] = ['dir' => $dir] + $overrides;

        return $raw;
    };
}

it('compiles the workbench content directory into the UIR byte-identical to its golden', function (): void {
    bindStubEngine();

    $document = generateDocument(withWorkbenchContent())->document;

    assertGolden('workbench-content.uir.json', (new UirEmitter)->emit($document));
});

it('strips the whole content layer from OpenAPI (byte-identical to the content-free golden)', function (): void {
    bindStubEngine();

    $document = generateDocument(withWorkbenchContent())->document;

    // The UIR carries content...
    expect($document->docuccino?->content?->pages ?? [])->not->toBeEmpty()
        ->and($document->docuccino?->content?->nav ?? [])->not->toBeEmpty();

    // ...and the OpenAPI emission drops it, matching the content-free openapi golden byte-for-byte.
    $openapi = (new OpenApi32Emitter)->emit($document);
    expect($openapi)->not->toContain('x-docuccino')
        ->and($openapi)->not->toContain('page:v1:')
        ->and($openapi)->toBe(file_get_contents(golden('workbench.openapi.json')));
});

it('resolves directives and the operation nav ref to stable ids', function (): void {
    bindStubEngine();

    $content = generateDocument(withWorkbenchContent())->document->docuccino?->content;
    $intro = collect($content?->pages ?? [])->firstWhere('slug', 'getting-started/introduction');

    // The operation id resolved into the body of the introduction page...
    expect($intro?->content)->toContain('::operation{id="GET /api/forms" ref="op:v1:')
        ->and($intro?->content)->toContain('::schema{name="FormData" ref="sch:v1:');

    // ...and the reference section carries an operation nav node pointing at the same op id.
    $reference = collect($content?->nav ?? [])->firstWhere('title', 'Reference');
    expect($reference?->children[0]->type)->toBe('operation')
        ->and($reference?->children[0]->ref)->toStartWith('op:v1:');
});

it('warns when the configured content directory is missing', function (): void {
    bindStubEngine();

    $result = generateDocument(withContent('/does/not/exist/'.uniqid()));

    $warnings = array_values(array_filter(
        $result->diagnostics,
        static fn ($d): bool => $d->code === 'content.dir-missing' && $d->severity === Severity::Warning,
    ));
    expect($warnings)->not->toBeEmpty()
        ->and($result->document->docuccino?->content)->toBeNull();
});

it('leaves the document unchanged for an empty content directory (no content key)', function (): void {
    bindStubEngine();

    $empty = sys_get_temp_dir().'/docuccino-empty-'.uniqid();
    mkdir($empty, 0777, true);

    $result = generateDocument(withContent($empty));

    expect($result->document->docuccino?->content)->toBeNull()
        ->and($result->diagnostics)->each->toBeInstanceOf(Diagnostic::class);

    rmdir($empty);
});

it('keeps content out of the fragment cache key (a prose edit does not invalidate fragments; an env change does)', function (): void {
    bindStubEngine();

    // A private, editable copy of the content tree + a temp fragment cache.
    $dir = sys_get_temp_dir().'/docuccino-content-'.uniqid();
    $cache = sys_get_temp_dir().'/docuccino-cache-'.uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/index.md', "---\ntitle: Index\n---\nOriginal body.\n");

    config()->set('docuccino.cache.enabled', true);
    config()->set('docuccino.cache.path', $cache);

    generateDocument(withContent($dir));
    $before = glob($cache.'/*') ?: [];
    expect($before)->not->toBeEmpty();

    // Editing a content file must NOT produce new fragment cache keys — operation fragments never
    // read content, so the prose edit is a warm hit across every route (content is picked up by the
    // always-fresh assembly step instead).
    file_put_contents($dir.'/index.md', "---\ntitle: Index\n---\nEdited body.\n");
    generateDocument(withContent($dir));
    $afterContentEdit = glob($cache.'/*') ?: [];
    expect(count($afterContentEdit))->toBe(count($before));

    // A genuine document-level environment change (app.url feeds Passport oauth2 flow URLs) DOES
    // change the env digest → new fragment cache keys (fresh files).
    config()->set('app.url', 'https://changed.example');
    generateDocument(withContent($dir));
    $afterEnvChange = glob($cache.'/*') ?: [];
    expect(count($afterEnvChange))->toBeGreaterThan(count($afterContentEdit));

    array_map('unlink', glob($cache.'/*') ?: []);
    @rmdir($cache);
    unlink($dir.'/index.md');
    rmdir($dir);
});
