<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Support\ConfinedPath;

/**
 * Resolves a configured or user-supplied filesystem path against the app base directory. Unlike
 * {@see ConfinedPath}, this does not confine the result — the export target, an overlay glob and a
 * diff's old-artifact path are trusted inputs that may legitimately point anywhere (including an
 * absolute path outside the project). The single owner of the "absolute unless it already is" join
 * every command and the viewer shared as a private copy.
 *
 * @internal
 */
final class Paths
{
    /** Resolve $path against $base unless it is already absolute. */
    public static function absolute(string $path, string $base): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($base, '/').'/'.ltrim($path, '/');
    }

    /**
     * The inverse of {@see absolute()}: $path expressed relative to $base, or null when an absolute
     * $path does not sit inside $base. An already-relative $path comes back unchanged (it is
     * base-relative by definition); a $path equal to $base becomes `.`.
     *
     * Containment is decided LEXICALLY (`.` / `..` collapsed, no filesystem access), so a path that
     * reaches the app only through a symlink reads as outside rather than being silently rewritten —
     * and glob patterns, which no `realpath()` could resolve, obey the very same rule.
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
