<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Http;

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\ViewerContext;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Runtime\DocumentCache;
use Docuccino\Laravel\Support\Paths;
use Docuccino\Laravel\Viewer\ScalarViewer;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * The runtime viewer endpoints (design §Multiple documents / §5 serving): the Scalar HTML page,
 * the `.json` spec (served per `viewer.source`: generate | artifact | cache), and the locally
 * bundled Scalar asset. Every route is guarded by {@see authorize()} — a configured `viewer.gate`
 * ability, else local environment only.
 */
final class DocsController
{
    public function __construct(
        private readonly DocumentBuilder $builder,
        private readonly ScalarViewer $viewer,
    ) {}

    public function show(string $document): Response
    {
        $config = $this->config($document);
        $this->authorize($config);

        return new Response($this->viewer->render(new ViewerContext($config)), 200, ['Content-Type' => 'text/html']);
    }

    public function spec(string $document, TypeEngine $engine, DocumentCache $cache): Response
    {
        $config = $this->config($document);
        $this->authorize($config);

        $source = $config->viewer['source'] ?? 'generate';
        $json = match ($source) {
            'artifact' => $this->fromArtifact($config),
            'cache' => $cache->get($document) ?? $this->coldCacheFallback($document, $engine),
            default => $this->generate($document, $engine),
        };

        return new Response($json, 200, ['Content-Type' => 'application/json']);
    }

    private function generate(string $document, TypeEngine $engine): string
    {
        return (new OpenApi32Emitter)->emit($this->builder->build($document, $engine)->document);
    }

    /**
     * `viewer.source: cache` with a cold cache falls back to generating the spec rather than serving
     * an empty document (arch F11), logging a warning so the missed `docuccino:cache` warm-up is
     * surfaced instead of silently degrading.
     */
    private function coldCacheFallback(string $document, TypeEngine $engine): string
    {
        Log::warning(sprintf(
            'Docuccino viewer "%s" is configured with source=cache but the cache is cold; generating on the fly. Run `docuccino:cache` to warm it.',
            $document,
        ));

        return $this->generate($document, $engine);
    }

    public function asset(): Response
    {
        $path = dirname(__DIR__, 2).'/resources/js/scalar.standalone.js';
        $contents = @file_get_contents($path);

        if ($contents === false) {
            // Serving a blank viewer silently makes "the docs page is empty" undiagnosable; log it.
            Log::warning(sprintf('Docuccino viewer asset could not be read at "%s"; serving an empty body.', $path));
            $contents = '';
        }

        return new Response($contents, 200, [
            'Content-Type' => 'application/javascript',
            // The bundle is versioned by the package release (it only changes on upgrade), so it is
            // safe to cache immutably and spares repeat 3.6 MB reads on every viewer load.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function config(string $document): DocumentConfig
    {
        abort_unless($this->builder->hasDocument($document), 404);

        return $this->builder->config($document);
    }

    private function authorize(DocumentConfig $config): void
    {
        $gate = $config->viewer['gate'] ?? null;

        $allowed = is_string($gate) && $gate !== ''
            ? Gate::allows($gate)
            : app()->environment('local') === true;

        abort_unless($allowed, 403);
    }

    private function fromArtifact(DocumentConfig $config): string
    {
        $absolute = Paths::absolute($config->exportPath(), base_path());
        $contents = @file_get_contents($absolute);
        if ($contents === false) {
            return '';
        }

        // A UIR artifact (carries the `uir` field) is not a viewer-consumable OpenAPI document; the
        // Scalar viewer expects OAS, so re-emit it through the OpenAPI 3.2 emitter (security L1 —
        // never stream a UIR's internal x-docuccino provenance to the browser). A plain OpenAPI
        // artifact streams through unchanged.
        $decoded = json_decode($contents, true);
        if (is_array($decoded) && isset($decoded['uir'])) {
            /** @var array<string, mixed> $decoded */
            return (new OpenApi32Emitter)->emit(UirDocument::fromArray($decoded));
        }

        return $contents;
    }
}
