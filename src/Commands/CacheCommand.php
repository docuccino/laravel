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
 * the document's own viewer ({@see ViewerDrivers::emitResultFor()}), and the entry records that
 * format so a driver switch is a cache miss rather than the wrong version served forever.
 *
 * This warms an artifact, so it owes the operator what the emitter said about it. A request has
 * nobody to tell and logs instead; a command has a console, and a payload that is not a valid
 * document of its own format would otherwise be cached, served, and reported nowhere anyone was
 * looking.
 */
final class CacheCommand extends Command
{
    use GuardsEnabled;
    use IteratesDocuments;
    use RefusesUnreadConfig;
    use RendersDiagnostics;

    protected $signature = 'docuccino:cache
        {document? : The configured document key (defaults to every document)}
        {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}';

    protected $description = 'Build and cache the API document(s) for the runtime endpoint.';

    public function handle(DocumentBuilder $builder, TypeEngine $engine, DocumentCache $cache, ViewerDrivers $drivers): int
    {
        if ($this->abortIfDisabled() || $this->abortIfConfigUnread()) {
            return self::FAILURE;
        }

        return $this->forEachDocument($builder, function (string $key) use ($builder, $engine, $cache, $drivers): int {
            $result = $builder->build($key, $engine);
            $config = $builder->config($key);
            $format = $drivers->formatFor($config);
            $emitted = $drivers->emitResultFor($config, $result->document);

            // Cached even when the emitter says it is invalid, for the reason the export still writes
            // the file: a partial answer the reader can look at beats a viewer with nothing behind it,
            // and the exit code is what tells CI.
            $cache->put($key, $emitted->output, $format);

            $this->info(sprintf('Cached document "%s".', $key));
            $this->renderDiagnostics($key, $result->diagnostics);
            $this->renderDiagnostics($format, $emitted->report->diagnostics);

            return $emitted->report->hasError() ? self::FAILURE : self::SUCCESS;
        });
    }
}
