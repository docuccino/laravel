<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Pipeline\FragmentStore;
use Docuccino\Laravel\Runtime\DocumentCache;
use Illuminate\Console\Command;

/**
 * Forgets the cached payload for a document (or every document), the inverse of {@see CacheCommand}.
 * `--fragments` additionally empties the per-operation fragment store, which is shared by every
 * document and survives `docuccino.cache.enabled` being turned off — it is the supported recovery
 * from a fragment store you no longer trust.
 */
final class ClearCommand extends Command
{
    use IteratesDocuments;

    protected $signature = 'docuccino:clear
        {document? : The configured document key (defaults to every document)}
        {--fragments : Also empty the per-operation fragment cache}';

    protected $description = 'Clear the cached runtime API document(s).';

    public function handle(DocumentBuilder $builder, DocumentCache $cache, FragmentStore $fragments): int
    {
        $exit = $this->forEachDocument($builder, function (string $key) use ($cache): int {
            $cache->forget($key);
            $this->info(sprintf('Cleared cached document "%s".', $key));

            return self::SUCCESS;
        });

        if ($exit === self::SUCCESS && $this->option('fragments')) {
            $this->info(sprintf('Cleared %d cached operation fragment(s).', $fragments->clear()));
        }

        return $exit;
    }
}
