<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Pipeline;

use Docuccino\Core\Pipeline\FragmentCache;

/**
 * Where a Laravel app keeps its operation fragments, the one supported way to empty them
 * (`docuccino:clear --fragments`), and the way to read back what they were built from. The provider
 * builds the core {@see FragmentCache} from this, so the store a build writes, the store the command
 * clears and the store `docuccino:watch` reads can never drift apart.
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
     * Every file the stored fragments were built from — the manifest {@see FragmentCache::put()}
     * writes and {@see FragmentCache::get()} re-hashes for freshness, and therefore the exact set
     * `docuccino:watch` has to watch to know a rebuild would say something different.
     *
     * Read whether or not the cache is enabled, same as {@see clear()}: the store outlives the
     * switch, and the process reading it is not always the one that wrote it. An entry this can't
     * make sense of contributes nothing rather than failing the read — a watch set short one file
     * costs a missed rebuild, and a fatal here costs the whole session.
     *
     * @return list<string> absolute paths, deduped and sorted
     */
    public function dependencyFiles(): array
    {
        $files = [];

        foreach (glob(rtrim($this->path, '/').'/*.json') ?: [] as $entry) {
            $raw = @file_get_contents($entry);
            if ($raw === false) {
                continue;
            }

            $decoded = json_decode($raw, true);
            $dependencies = is_array($decoded) && is_array($decoded['dependencies'] ?? null) ? $decoded['dependencies'] : [];

            foreach ($dependencies as $dependency) {
                $file = is_array($dependency) ? ($dependency['file'] ?? null) : null;
                if (is_string($file) && $file !== '') {
                    $files[$file] = true;
                }
            }
        }

        $files = array_keys($files);
        sort($files, SORT_STRING);

        return $files;
    }

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
