<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Watch;

use Docuccino\Core\Extensions\Context\ExportTarget;
use Docuccino\Laravel\Engine\EngineNeon;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Pipeline\FragmentStore;
use Docuccino\Laravel\Support\Paths;
use Docuccino\Laravel\Versioning\ChangeDirectories;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * What `docuccino:watch` watches, and the stamp it compares between polls.
 *
 * The operation half is not a guess: every fragment records the files its operation was recovered
 * from — the action, everything inheritance and traits answered for it, every file a trace walked —
 * and that is the same list a rebuild re-hashes to decide the fragment is stale. Watching anything
 * else would be a second opinion about what a build depends on, and the two would drift.
 *
 * The rest is what invalidates every operation rather than one: config, route definitions, the
 * narrative content tree, overlay files, the webhook and version-change directories, the lock file the
 * build fingerprint digests, and the engine's own PHPStan config. Those are watched as ROOTS rather
 * than as files, so a route file, a content page or a webhook class that did not exist when the session
 * started still registers as a change — until a thing has a fragment behind it, the operation half
 * cannot see it.
 *
 * Export targets are subtracted from all of it. A build writes those, so watching them would see its
 * own output and rebuild forever.
 *
 * @internal
 */
final readonly class WatchSet
{
    /**
     * @param  array<string, mixed>  $engineConfig  the `docuccino.engine` bag, for the `neon` file it may name
     */
    public function __construct(
        private DocumentBuilder $builder,
        private FragmentStore $fragments,
        private string $basePath,
        private array $engineConfig = [],
    ) {}

    /**
     * The files the built operations were recovered from. Empty before the first build has written a
     * fragment — and, after one, a sign that nothing was stored, which is the whole watch set
     * quietly collapsing to config and routes.
     *
     * @return list<string>
     */
    public function operationFiles(): array
    {
        return $this->fragments->dependencyFiles();
    }

    /**
     * The directories and files that decide every operation at once.
     *
     * @param  list<string>  $documents
     * @return list<string>
     */
    public function documentRoots(array $documents): array
    {
        $roots = [
            $this->path('config'),
            $this->path('routes'),
            $this->path('composer.json'),
            $this->path('composer.lock'),
        ];

        $neon = EngineNeon::path($this->engineConfig, $this->basePath);
        if ($neon !== null) {
            $roots[] = $neon;
        }

        foreach ($documents as $key) {
            $config = $this->builder->config($key);

            $content = $config->contentDir();
            if ($content !== null && $content !== '') {
                $roots[] = Paths::absolute($content, $this->basePath);
            }

            $webhooks = $config->webhooksDir();
            if ($webhooks !== null && $webhooks !== '') {
                $roots[] = Paths::absolute($webhooks, $this->basePath);
            }

            // Through the collector's own resolver, globs and confinement included: two readings of one
            // config key is how a build reads a module's changes that a watch session never notices.
            foreach (ChangeDirectories::resolve($this->basePath, $config)[0] as $changes) {
                $roots[] = $changes;
            }

            foreach ($config->overlays as $pattern) {
                foreach (glob(Paths::absolute($pattern, $this->basePath)) ?: [] as $overlay) {
                    $roots[] = $overlay;
                }
            }
        }

        return $roots;
    }

    /**
     * Everything watched for this run, deduped and sorted, with the artifacts a build writes taken
     * back out again.
     *
     * @param  list<string>  $documents
     * @return list<string>
     */
    public function roots(array $documents): array
    {
        $written = $this->exportTargets($documents);

        $roots = [];
        foreach ([...$this->operationFiles(), ...$this->documentRoots($documents)] as $root) {
            if (! isset($written[$root])) {
                $roots[$root] = true;
            }
        }

        $roots = array_keys($roots);
        sort($roots, SORT_STRING);

        return $roots;
    }

    /**
     * One reading of the watched tree: every file under those roots, stamped with its modification
     * time and size. Size is in because a same-second rewrite of a different length is the edit a
     * one-second mtime would miss; a same-second rewrite of the same length is the one it still can,
     * and the next edit catches it.
     *
     * A root that has gone simply contributes nothing, so its disappearance reads as a change.
     *
     * @param  list<string>  $roots
     * @return array<string, string> absolute path => stamp
     */
    public function snapshot(array $roots): array
    {
        $stamps = [];

        foreach ($roots as $root) {
            if (is_dir($root)) {
                foreach ($this->filesUnder($root) as $file) {
                    $stamps[$file] = self::stamp($file);
                }

                continue;
            }

            if (is_file($root)) {
                $stamps[$root] = self::stamp($root);
            }
        }

        ksort($stamps, SORT_STRING);

        return $stamps;
    }

    /**
     * What moved between two readings: added, removed and rewritten files alike, since all three
     * mean the build would now say something else.
     *
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     * @return list<string>
     */
    public static function changed(array $before, array $after): array
    {
        $changed = [];

        foreach ($after as $file => $stamp) {
            if (($before[$file] ?? null) !== $stamp) {
                $changed[] = $file;
            }
        }

        foreach ($before as $file => $stamp) {
            if (! array_key_exists($file, $after)) {
                $changed[] = $file;
            }
        }

        sort($changed, SORT_STRING);

        return $changed;
    }

    /**
     * The absolute path of every artifact the selected documents write.
     *
     * @param  list<string>  $documents
     * @return array<string, true>
     */
    private function exportTargets(array $documents): array
    {
        $targets = [];

        foreach ($documents as $key) {
            foreach ($this->builder->config($key)->exportTargets() as $target) {
                $targets[$this->targetPath($target)] = true;
            }
        }

        return $targets;
    }

    private function targetPath(ExportTarget $target): string
    {
        return Paths::absolute($target->path, $this->basePath);
    }

    /**
     * Every file under a directory, dot-entries and their trees skipped — the filter refuses to
     * DESCEND rather than only to yield, so a `.git` beside a content tree costs nothing to walk.
     *
     * @return list<string>
     */
    private function filesUnder(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
                static fn (SplFileInfo $entry): bool => ! str_starts_with($entry->getBasename(), '.'),
            ),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }

    private function path(string $relative): string
    {
        return rtrim($this->basePath, '/').'/'.$relative;
    }

    /** Unreadable stats degrade to a constant rather than to a value that flaps every poll. */
    private static function stamp(string $file): string
    {
        $mtime = @filemtime($file);
        $size = @filesize($file);

        return ($mtime === false ? '?' : (string) $mtime).':'.($size === false ? '?' : (string) $size);
    }
}
