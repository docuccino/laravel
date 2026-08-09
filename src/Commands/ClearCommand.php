<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Runtime\DocumentCache;
use Illuminate\Console\Command;

/**
 * Forgets the cached payload for a document (or every document), the inverse of {@see CacheCommand}.
 */
final class ClearCommand extends Command
{
    use IteratesDocuments;

    protected $signature = 'docuccino:clear {document? : The configured document key (defaults to every document)}';

    protected $description = 'Clear the cached runtime API document(s).';

    public function handle(DocumentBuilder $builder, DocumentCache $cache): int
    {
        return $this->forEachDocument($builder, function (string $key) use ($cache): int {
            $cache->forget($key);
            $this->info(sprintf('Cleared cached document "%s".', $key));

            return self::SUCCESS;
        });
    }
}
