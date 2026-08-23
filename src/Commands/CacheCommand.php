<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Runtime\DocumentCache;
use Docuccino\Laravel\Viewer\ViewerDrivers;
use Illuminate\Console\Command;

/**
 * Builds a document (or every document) and stores its OpenAPI payload in the Laravel cache, so the
 * runtime endpoint can answer `viewer.source: cache` without rebuilding. The payload is emitted for
 * the document's own viewer ({@see ViewerDrivers::emitFor()}), and the entry records that
 * format so a driver switch is a cache miss rather than the wrong version served forever.
 */
final class CacheCommand extends Command
{
    use GuardsEnabled;
    use IteratesDocuments;
    use RendersDiagnostics;

    protected $signature = 'docuccino:cache
        {document? : The configured document key (defaults to every document)}
        {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}';

    protected $description = 'Build and cache the API document(s) for the runtime endpoint.';

    public function handle(DocumentBuilder $builder, TypeEngine $engine, DocumentCache $cache, ViewerDrivers $drivers): int
    {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        return $this->forEachDocument($builder, function (string $key) use ($builder, $engine, $cache, $drivers): int {
            $result = $builder->build($key, $engine);
            $config = $builder->config($key);
            $cache->put($key, $drivers->emitFor($config, $result->document), $drivers->formatFor($config));

            $this->info(sprintf('Cached document "%s".', $key));
            $this->renderDiagnostics($key, $result->diagnostics);

            return self::SUCCESS;
        });
    }
}
