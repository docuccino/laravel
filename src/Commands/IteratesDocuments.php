<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Illuminate\Console\Command;

/**
 * Shared `{document?}` argument handling for the artisan commands: an unknown document key errors
 * out, otherwise the body runs for the selected document (or every configured document when the
 * argument is omitted). The per-document exit codes aggregate — any FAILURE fails the command.
 *
 * @mixin Command
 */
trait IteratesDocuments
{
    /**
     * @param  callable(string): int  $body  the per-document body, returning an exit code
     */
    protected function forEachDocument(DocumentBuilder $builder, callable $body): int
    {
        $only = $this->argument('document');
        if (is_string($only) && ! $builder->hasDocument($only)) {
            $this->error(sprintf('Unknown document "%s".', $only));

            return self::FAILURE;
        }

        $exit = self::SUCCESS;
        foreach ($builder->documentKeys() as $key) {
            if (is_string($only) && $key !== $only) {
                continue;
            }
            if ($body($key) === self::FAILURE) {
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }
}
