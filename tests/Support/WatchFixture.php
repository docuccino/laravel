<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A throwaway application tree for the watch suites: `config/`, `routes/`, a controller, an artifact
 * a build would write, and a fragment store naming the first two of those as one operation's
 * dependencies.
 */
final class WatchFixture
{
    private function __construct(public readonly string $root) {}

    public static function make(): self
    {
        $root = sys_get_temp_dir().'/docuccino-watch-'.uniqid('', true);

        foreach (['config', 'routes', 'app', 'docs', 'fragments'] as $directory) {
            mkdir($root.'/'.$directory, 0755, true);
        }

        file_put_contents($root.'/config/docuccino.php', '<?php return [];');
        file_put_contents($root.'/routes/api.php', '<?php');
        file_put_contents($root.'/composer.json', '{}');
        file_put_contents($root.'/composer.lock', '{}');
        file_put_contents($root.'/app/InvoiceController.php', '<?php');
        file_put_contents($root.'/docs/openapi.json', '{}');

        $fixture = new self($root);
        $fixture->storeFragment([$root.'/app/InvoiceController.php', $root.'/docs/openapi.json']);

        return $fixture;
    }

    /**
     * One stored fragment naming those dependency files — the shape `FragmentCache::put()` writes.
     *
     * @param  list<string>  $dependencies
     */
    public function storeFragment(array $dependencies, string $name = 'a'): void
    {
        file_put_contents($this->root.'/fragments/'.$name.'.json', (string) json_encode([
            'format' => 2,
            'dependencies' => array_map(
                static fn (string $file): array => ['file' => $file, 'hash' => 'stale'],
                $dependencies,
            ),
        ]));
    }

    public function path(string $relative): string
    {
        return $this->root.'/'.$relative;
    }

    public function remove(): void
    {
        if (! is_dir($this->root)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
        }

        @rmdir($this->root);
    }
}
