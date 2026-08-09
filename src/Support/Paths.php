<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Support\ConfinedPath;

/**
 * Resolves a configured filesystem path against the app base directory. Unlike {@see ConfinedPath} it
 * does NOT confine the result: the export target, an overlay glob and a diff's old-artifact path are
 * trusted inputs that may legitimately point outside the project.
 *
 * @internal
 */
final class Paths
{
    /** $path joined onto $base, unless it's already absolute. */
    public static function absolute(string $path, string $base): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($base, '/').'/'.ltrim($path, '/');
    }

    /**
     * The inverse of {@see absolute()}, or null when an absolute $path isn't inside $base. A relative
     * $path comes back unchanged; $path equal to $base becomes `.`.
     *
     * Containment is lexical (`.`/`..` collapsed, no filesystem access), so a path that only reaches
     * the app through a symlink reads as outside rather than being silently rewritten — and glob
     * patterns, which `realpath()` couldn't resolve anyway, follow the same rule.
     */
    public static function relative(string $path, string $base): ?string
    {
        if (! str_starts_with($path, '/')) {
            return $path;
        }

        $normalizedBase = ConfinedPath::normalize($base);
        $normalized = ConfinedPath::normalize($path);

        if ($normalized === $normalizedBase) {
            return '.';
        }

        $prefix = rtrim($normalizedBase, '/').'/';

        return str_starts_with($normalized, $prefix) ? substr($normalized, strlen($prefix)) : null;
    }
}
