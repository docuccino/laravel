<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Composer\Autoload\ClassLoader;

/**
 * Where a class WOULD be written under the application's PSR-4 map, whether or not the file is there
 * yet. Used to key a fragment on a class that does not exist: the cache records a missing dependency
 * as absent rather than skipping it, so a path with nothing at it today invalidates the moment
 * somebody creates the file — which is the only way a build can notice a class it looked for and did
 * not find.
 *
 * An application autoloading by classmap, or one whose loader this cannot reach, yields no candidates
 * and simply keeps whatever keying it already had.
 */
final class Psr4ClassFile
{
    /**
     * Every path the PSR-4 map would accept for `$class`, sorted — so what a build records never
     * depends on the order the prefixes were registered in.
     *
     * @return list<string>
     */
    public static function candidates(string $class): array
    {
        $class = ltrim($class, '\\');
        $paths = [];

        foreach (self::prefixes() as $prefix => $directories) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            $tail = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            foreach ($directories as $directory) {
                $paths[] = rtrim(str_replace('\\', '/', $directory), '/').'/'.$tail;
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    /**
     * The registered PSR-4 prefixes, off Composer's own loaders — EVERY one of them, merged. Nothing
     * else publishes the map, and a runtime without it is one this says nothing about. Taking the first
     * loader and stopping gives an application that registered a second one half a map, which for a
     * cache key is a fragment keyed on paths the class is not at.
     *
     * @return array<string, list<string>>
     */
    private static function prefixes(): array
    {
        if (! class_exists(ClassLoader::class)) {
            return [];
        }

        $prefixes = [];
        foreach (spl_autoload_functions() as $autoloader) {
            if (! is_array($autoloader) || ! ($autoloader[0] instanceof ClassLoader)) {
                continue;
            }

            foreach ($autoloader[0]->getPrefixesPsr4() as $prefix => $directories) {
                foreach ($directories as $directory) {
                    $prefixes[$prefix][] = $directory;
                }
            }
        }

        return array_map(
            static fn (array $directories): array => array_values(array_unique($directories)),
            $prefixes,
        );
    }
}
