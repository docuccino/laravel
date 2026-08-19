<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Illuminate\Console\Command;

/**
 * Shared `{document?}` handling: an unknown key errors, otherwise the body runs for that document, or
 * for all of them when the argument is omitted. Exit codes aggregate — any failure fails the command.
 *
 * @mixin Command
 */
trait IteratesDocuments
{
    /**
     * The documents this run covers: the one the argument names, or every configured key in config
     * order. Null — having printed why — when the argument names none. Split out because a command
     * that doesn't run per document still has to resolve the same argument the same way.
     *
     * @return list<string>|null
     */
    protected function selectedDocuments(DocumentBuilder $builder): ?array
    {
        $only = $this->argument('document');
        if (is_string($only) && ! $builder->hasDocument($only)) {
            $this->error(sprintf('Unknown document "%s".', $only));

            return null;
        }

        return array_values(array_filter(
            $builder->documentKeys(),
            static fn (string $key): bool => ! is_string($only) || $key === $only,
        ));
    }

    /**
     * @param  callable(string): int  $body  the per-document body, returning an exit code
     */
    protected function forEachDocument(DocumentBuilder $builder, callable $body): int
    {
        $keys = $this->selectedDocuments($builder);
        if ($keys === null) {
            return self::FAILURE;
        }

        $exit = self::SUCCESS;
        foreach ($keys as $key) {
            if ($body($key) === self::FAILURE) {
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }
}
