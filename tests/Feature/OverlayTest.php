<?php

declare(strict_types=1);

use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * An overlay the build cannot use is a warning, never a fatal — a file the YAML parser chokes on and a
 * well-formed file that isn't an Overlay 1.0 document both come back as one `overlay.invalid` warning
 * with the document intact.
 */
beforeEach(function (): void {
    // Outside the app base path on purpose, so the config factory keeps the glob absolute.
    $this->overlayDir = sys_get_temp_dir().'/docuccino-overlay-'.uniqid();
    mkdir($this->overlayDir);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->overlayDir.'/*') ?: []);
    @rmdir($this->overlayDir);
});

it('degrades an unparseable overlay YAML file to a warning', function (): void {
    // An unterminated inline sequence: Yaml::parseFile() throws ParseException, not an overlay error.
    file_put_contents($this->overlayDir.'/broken.yaml', "overlay: 1.0.0\nactions: [ { target: '\$' }\n");
    config()->set('docuccino.documents.default.overlays', [$this->overlayDir.'/*.yaml']);

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    $warnings = diagnosticsCoded($result->diagnostics, 'overlay.invalid');
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->severity->value)->toBe('warning')
        ->and($warnings[0]->message)->toContain('broken.yaml')
        // The build still produced a document rather than blowing up on the way past.
        ->and($result->document->toArray())->toHaveKey('paths');
});

it('degrades an unsupported overlay version to a warning', function (): void {
    file_put_contents($this->overlayDir.'/future.yaml', "overlay: 2.0.0\nactions: []\n");
    config()->set('docuccino.documents.default.overlays', [$this->overlayDir.'/*.yaml']);

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    $warnings = diagnosticsCoded($result->diagnostics, 'overlay.invalid');
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->message)->toContain('future.yaml')
        ->and($result->document->toArray())->toHaveKey('paths');
});

it('cannot reach a shared error response component, because transformers make those after it runs', function (): void {
    // Overlays run one line ahead of document transformers, and the shared error components are a
    // transformer's output — so an overlay aimed at one matches nothing and says so. The errors guide
    // sends authors wanting to edit these to a DocumentTransformer for exactly this reason.
    file_put_contents($this->overlayDir.'/shared.yaml', <<<'YAML'
        overlay: 1.0.0
        actions:
          - target: $.components.responses.NotFound
            update:
              description: Overlaid
        YAML);
    config()->set('docuccino.documents.default.overlays', [$this->overlayDir.'/*.yaml']);

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());
    $document = $result->document->toArray();

    expect(diagnosticsCoded($result->diagnostics, 'overlay.target-missing'))->toHaveCount(1)
        // The component is in the finished document — it just arrived after the overlay had run.
        ->and($document['components']['responses'])->toHaveKey('NotFound')
        ->and($document['components']['responses']['NotFound']['description'] ?? null)->not->toBe('Overlaid');
});

it('degrades an overlay glob no filesystem can hold to a warning', function (): void {
    // `glob()` raises a `ValueError` on a NUL byte and `@` does not suppress a throw, so this pattern
    // took the whole build with it and named `glob()` rather than the config key that held it. The
    // refusal is at the config boundary now, so the usable pattern beside it still applies.
    file_put_contents($this->overlayDir.'/title.yaml', <<<'YAML'
        overlay: 1.0.0
        actions:
          - target: $.info
            update:
              title: Overlaid Title
        YAML);
    config()->set('docuccino.documents.default.overlays', ["resources\0/overlays/*.yaml", $this->overlayDir.'/*.yaml']);

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    $rejected = diagnosticsCoded($result->diagnostics, 'config.path-rejected');
    expect($rejected)->toHaveCount(1)
        ->and($rejected[0]->severity->value)->toBe('warning')
        ->and($rejected[0]->message)->toContain('overlays.0')
        ->and($result->document->toArray()['info']['title'] ?? null)->toBe('Overlaid Title');
});

it('degrades a fragment-cache directory no filesystem can hold to a cold build and a warning', function (): void {
    // Same byte, a different reader: `FragmentCache` reads and writes with `file_get_contents()` and
    // `mkdir()`, so this killed the build from inside the cache. A cache that is off costs one rebuild
    // per route and answers exactly what a warm one would.
    config()->set('docuccino.cache.enabled', true);
    config()->set('docuccino.cache.path', "storage/frag\0ments");

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    $rejected = diagnosticsCoded($result->diagnostics, 'config.path-rejected');
    expect($rejected)->toHaveCount(1)
        ->and($rejected[0]->message)->toContain('cache.path')
        ->and($result->document->toArray())->toHaveKey('paths');
});

it('applies a valid overlay without warning', function (): void {
    file_put_contents($this->overlayDir.'/title.yaml', <<<'YAML'
        overlay: 1.0.0
        info:
          title: Retitle
          version: 1.0.0
        actions:
          - target: $.info
            update:
              title: Overlaid Title
        YAML);
    config()->set('docuccino.documents.default.overlays', [$this->overlayDir.'/*.yaml']);

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    expect(diagnosticsCoded($result->diagnostics, 'overlay.invalid'))->toBe([])
        ->and($result->document->toArray()['info']['title'] ?? null)->toBe('Overlaid Title');
});
