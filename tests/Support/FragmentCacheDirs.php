<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

/**
 * The fragment-cache directories THIS process made, so a suite sweeping up in `afterEach` takes away its
 * own and nobody else's. Every directory is `docuccino-<slug>-<uniqid>`, so sweeping the slug's glob
 * instead would have one parallel worker delete a directory another is mid-build against — and two
 * suites sharing the `warm`/`cold` slugs is the ordinary case, not a clash to rename away.
 */
final class FragmentCacheDirs
{
    /** @var array<string, list<string>> slug → directories this process created */
    private static array $created = [];

    public static function record(string $slug, string $dir): void
    {
        self::$created[$slug][] = $dir;
    }

    /** @return list<string> */
    public static function take(string $slug): array
    {
        $dirs = self::$created[$slug] ?? [];
        unset(self::$created[$slug]);

        return $dirs;
    }
}
