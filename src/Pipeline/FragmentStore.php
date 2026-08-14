<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Pipeline;

use Docuccino\Core\Pipeline\FragmentCache;

/**
 * Where a Laravel app keeps its operation fragments, and the one supported way to empty them
 * (`docuccino:clear --fragments`). The provider builds the core {@see FragmentCache} from this, so
 * the store a build writes and the store the command clears can never drift apart.
 *
 * @internal
 */
final readonly class FragmentStore
{
    public function __construct(
        public bool $enabled,
        public string $path,
    ) {}

    /**
     * Deletes every stored fragment (and any temp file a crashed write left behind), returning how
     * many fragments went. The directory itself — and the `.gitignore` in it — is left alone.
     */
    public function clear(): int
    {
        $directory = rtrim($this->path, '/');

        $cleared = 0;
        foreach (glob($directory.'/*.json') ?: [] as $file) {
            if (@unlink($file)) {
                $cleared++;
            }
        }

        foreach (glob($directory.'/*.tmp') ?: [] as $temp) {
            @unlink($temp);
        }

        return $cleared;
    }
}
