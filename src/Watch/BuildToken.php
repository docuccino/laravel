<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Watch;

use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Support\Paths;

/**
 * The token a rebuild publishes through {@see WatchSignal}: a digest of the artifacts the selected
 * documents have on disk.
 *
 * It digests the OUTPUT rather than the inputs on purpose. A rebuild triggered by an edit that
 * changed no documented byte — a comment, a file the build reads and ignores — must leave an open
 * viewer where it was, and only what was written can answer that. Targets are read in path order and
 * an absent one digests as such, so the token is a function of the artifact set and nothing else.
 *
 * @internal
 */
final readonly class BuildToken
{
    public function __construct(
        private DocumentBuilder $builder,
        private string $basePath,
    ) {}

    /**
     * @param  list<string>  $documents
     */
    public function of(array $documents): string
    {
        $paths = [];
        foreach ($documents as $key) {
            foreach ($this->builder->config($key)->exportTargets() as $target) {
                $paths[Paths::absolute($target->path, $this->basePath)] = true;
            }
        }

        $paths = array_keys($paths);
        sort($paths, SORT_STRING);

        $parts = [];
        foreach ($paths as $path) {
            $hash = @hash_file('sha256', $path);
            $parts[] = $path."\0".($hash === false ? '' : $hash);
        }

        return hash('sha256', implode("\0", $parts));
    }
}
