<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The classes declared in the `*.php` files under a directory, read from the source rather than from
 * an autoloader, so a file the app never loads is still discovered. Both directory-scanning
 * declarations use it — `#[Webhook]` classes and API version changes — which is why it is named for
 * what it finds rather than for either caller.
 *
 * Files are visited in sorted order and the answer is sorted by FQCN, so nothing downstream can
 * inherit the filesystem's enumeration order. Tokenising is the whole job — `Foo::class` and
 * `new class` both spell `class` and neither declares one.
 *
 * @internal
 */
final class DeclaredClasses
{
    /**
     * @return list<class-string> declared classes that are loadable, sorted
     */
    public static function in(string $dir): array
    {
        $classes = [];

        foreach (self::files($dir) as $file) {
            foreach (self::declaredIn($file) as $class) {
                if (class_exists($class)) {
                    $classes[$class] = true;
                }
            }
        }

        $names = array_keys($classes);
        sort($names, SORT_STRING);

        /** @var list<class-string> $names */
        return $names;
    }

    /**
     * @return list<string> absolute paths, sorted
     */
    private static function files(string $dir): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Every `class` a file declares, namespace-qualified. An unreadable file declares nothing.
     *
     * @return list<string>
     */
    private static function declaredIn(string $file): array
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            return [];
        }

        $tokens = @token_get_all($source);
        $namespace = '';
        $classes = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = self::nameAfter($tokens, $i);

                continue;
            }

            // `Foo::class` and `new class {}` are the two ways the keyword appears without declaring
            // anything; an anonymous class cannot carry a discoverable name either way.
            if ($token[0] !== T_CLASS || self::precededBy($tokens, $i, [T_DOUBLE_COLON, T_NEW])) {
                continue;
            }

            $name = self::nameAfter($tokens, $i);
            if ($name !== '') {
                $classes[] = $namespace === '' ? $name : $namespace.'\\'.$name;
            }
        }

        return $classes;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @param  list<int>  $kinds
     */
    private static function precededBy(array $tokens, int $index, array $kinds): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) && in_array($token[0], $kinds, true);
        }

        return false;
    }

    /**
     * The identifier following the token at $index — a namespace name or a class name.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nameAfter(array $tokens, int $index): string
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_string($token)) {
                return '';
            }

            if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            return in_array($token[0], [T_STRING, T_NAME_QUALIFIED], true) ? $token[1] : '';
        }

        return '';
    }
}
