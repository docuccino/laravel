<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\ExportTarget;
use Docuccino\Laravel\Support\Paths;

/**
 * Which file the contract assertions read.
 *
 * There is deliberately no option of its own: an application that exports a document has already said
 * where it lands, so the assertions read `export.targets` — the best of them a contract can be read
 * back out of, which is UIR when there is one because only UIR carries the provenance that makes a
 * failure actionable. A second place to name the file would be a second place to get it wrong.
 */
final class ArtifactLocator
{
    /** The artifact to assert against, as an absolute path. */
    public static function locate(DocumentConfig $config, string $basePath, ?string $override = null): string
    {
        if ($override !== null) {
            return Paths::absolute($override, $basePath);
        }

        return Paths::absolute(self::preferred($config)->path, $basePath);
    }

    /**
     * The best target the assertions can read, in {@see Formats::contractPreference()} order — a
     * function of which formats the document exports, never of the order they were listed in. The
     * first target is the last resort, reached only by a document exporting nothing readable as a
     * contract at all.
     */
    public static function preferred(DocumentConfig $config): ExportTarget
    {
        $targets = $config->exportTargets();

        foreach (Formats::contractPreference() as $format) {
            foreach ($targets as $target) {
                if ($target->format === $format) {
                    return $target;
                }
            }
        }

        return $targets[0];
    }
}
