<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Runtime\DocumentCache;
use Illuminate\Console\Command;

/**
 * Builds a document (or every document) and stores its OpenAPI payload in the Laravel cache, so the
 * runtime endpoint can answer `viewer.source: cache` without rebuilding.
 */
final class CacheCommand extends Command
{
    use GuardsEnabled;
    use IteratesDocuments;
    use RendersDiagnostics;

    protected $signature = 'docuccino:cache {document? : The configured document key (defaults to every document)}';

    protected $description = 'Build and cache the API document(s) for the runtime endpoint.';

    public function handle(DocumentBuilder $builder, TypeEngine $engine, DocumentCache $cache): int
    {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        return $this->forEachDocument($builder, function (string $key) use ($builder, $engine, $cache): int {
            $result = $builder->build($key, $engine);
            $cache->put($key, (new OpenApi32Emitter)->emit($result->document));

            $this->info(sprintf('Cached document "%s".', $key));
            $this->renderDiagnostics($key, $result->diagnostics);

            return self::SUCCESS;
        });
    }
}
