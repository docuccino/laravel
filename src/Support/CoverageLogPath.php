<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Extensions\Context\DocumentConfig;

/**
 * Where a document's contract-coverage logs live: `coverage.log`, or `storage/docuccino/coverage`.
 *
 * One resolver because two things have to land on the same directory — the test suite writing the
 * logs and `docuccino:coverage` merging them — and a default that only one of them knew would be a
 * gate reading an empty directory.
 *
 * @internal
 */
final class CoverageLogPath
{
    /** The directory, as an absolute path. $override is a call-site argument outranking config. */
    public static function resolve(DocumentConfig $config, string $basePath, ?string $override = null): string
    {
        $configured = $override ?? $config->coverageLogDir();

        return $configured === null
            ? storage_path('docuccino/coverage')
            : Paths::absolute($configured, $basePath);
    }
}
