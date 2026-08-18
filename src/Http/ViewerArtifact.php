<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Http;

use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\ExportTarget;

/**
 * Which of a document's export targets the viewer's `artifact` source reads.
 *
 * A function of the target SET, never of the order it was written in: the best format the viewer can
 * serve wins ({@see Formats::viewerPreference()}), so re-ordering the config list cannot change which
 * file the viewer shows. YAML targets are skipped — the spec endpoint serves `application/json`, and
 * a YAML body under that content type is a file the browser cannot read.
 *
 * @internal
 */
final class ViewerArtifact
{
    /** The target to serve, or null when the document writes nothing the viewer can use. */
    public static function of(DocumentConfig $config): ?ExportTarget
    {
        $targets = $config->exportTargets();

        foreach (Formats::viewerPreference() as $format) {
            foreach ($targets as $target) {
                if ($target->format === $format && ! $target->yaml()) {
                    return $target;
                }
            }
        }

        return null;
    }
}
