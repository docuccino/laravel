<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Laravel\Registry\ConfigDiagnostics;
use Docuccino\Laravel\Support\Paths;

/**
 * Owns which document-config keys hold filesystem paths, and relativises them against the app base
 * path at config-read time.
 *
 * Why: {@see DocumentConfig::hash()} digests the raw config bag and that digest is emitted as
 * `document.configHash`, so an absolute path would fold the build machine's layout into a committed
 * artifact and two checkouts at different paths would emit different bytes. Relativising makes the
 * hash depend on what the path means, not how it was spelled — `base_path('resources/docs/api')` and
 * `'resources/docs/api'` hash the same. Reads are unaffected: consumers already resolve relative
 * values against the base path ({@see Paths::absolute()}, {@see ConfinedPath::resolve()}).
 *
 * A path genuinely outside the app is left exactly as configured (rewriting would break the read) and
 * reported as a `config.machine-dependent-path` info diagnostic ({@see ConfigDiagnostics}) — except
 * for the {@see DESTINATION_KEYS}, which say where artifacts are WRITTEN rather than what they hold.
 * Those sit outside the hash entirely, so an out-of-tree one makes nothing machine-dependent.
 *
 * A path no filesystem call could accept at all is a different answer: {@see unholdable()} names it, the
 * reader was handed nothing, and the build says so rather than raising out of `glob()`.
 *
 * @internal
 */
final class ConfigPaths
{
    /**
     * Document-config keys holding filesystem paths, in a fixed order so diagnostics are
     * deterministic.
     *
     * Not here on purpose: the `viewer` bag, `servers[].url` and `integrations.passport.url` aren't
     * filesystem paths; `cache.path`, `engine.project_paths` and `engine.neon` are, but live outside
     * the per-document bag so they never reach a `configHash` or any emitted byte.
     *
     * @var array<string, PathShape>
     */
    private const PATH_KEYS = [
        'content.dir' => PathShape::Single,
        // A destination, but a HASHED one: only `export` is lifted out of DocumentConfig::hash(), so a
        // coverage directory named absolutely folds this machine's layout into the emitted configHash
        // exactly as a content directory would.
        'coverage.log' => PathShape::Single,
        'examples.recordings' => PathShape::Single,
        'export.path' => PathShape::Single,
        'export.targets' => PathShape::TargetList,
        'info.description.file' => PathShape::Single,
        'overlays' => PathShape::PathList,
        'webhooks.dir' => PathShape::Single,
    ];

    /**
     * Keys relativised for tidiness but exempt from {@see machineDependent()}: an export destination
     * is outside {@see DocumentConfig::hash()}, so pointing one out of tree makes nothing
     * machine-dependent. Relativising it is still worth doing — it is what lets two spellings of one
     * destination compare equal when the export command looks for duplicate targets.
     *
     * @var list<string>
     */
    private const DESTINATION_KEYS = ['export.path', 'export.targets'];

    /**
     * $config with every {@see PATH_KEYS} value inside $basePath rewritten base-relative. Already
     * relative, outside the base path, or the wrong shape for its key: returned untouched.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function relativize(array $config, string $basePath): array
    {
        foreach (self::PATH_KEYS as $key => $shape) {
            $segments = explode('.', $key);
            $value = self::get($config, $segments);

            if ($shape === PathShape::Single) {
                if (is_string($value) && ($rewritten = self::rewrite($value, $basePath)) !== $value) {
                    $config = self::set($config, $segments, $rewritten);
                }

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $rewritten = [];
            $changed = false;
            foreach ($value as $index => $entry) {
                $next = $shape === PathShape::TargetList
                    ? self::rewriteTarget($entry, $basePath)
                    : (is_string($entry) ? self::rewrite($entry, $basePath) : $entry);
                $changed = $changed || $next !== $entry;
                $rewritten[$index] = $next;
            }

            if ($changed) {
                $config = self::set($config, $segments, $rewritten);
            }
        }

        return $config;
    }

    /**
     * Keys of an already-relativized config whose value is still absolute, i.e. outside the app and so
     * machine-dependent. Dotted key + offending path, in {@see PATH_KEYS} order.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{key: string, path: string}>
     */
    public static function machineDependent(array $config): array
    {
        $found = [];

        foreach (self::PATH_KEYS as $key => $shape) {
            if (in_array($key, self::DESTINATION_KEYS, true)) {
                continue;
            }

            $value = self::get($config, explode('.', $key));

            if ($shape !== PathShape::Single) {
                foreach (is_array($value) ? $value : [] as $index => $entry) {
                    if (is_string($entry) && str_starts_with($entry, '/')) {
                        $found[] = ['key' => $key.'.'.(is_int($index) ? (string) $index : $index), 'path' => $entry];
                    }
                }

                continue;
            }

            if (is_string($value) && str_starts_with($value, '/')) {
                $found[] = ['key' => $key, 'path' => $value];
            }
        }

        return $found;
    }

    /**
     * Keys whose value no filesystem call could accept — a NUL byte ({@see ConfinedPath::holdable()}).
     * Dotted key + offending path, in {@see PATH_KEYS} order, exactly as {@see machineDependent()}
     * reports its own; every one of these has been refused by the time a reader sees it, so this is
     * what says a configured path was dropped rather than acted on.
     *
     * Every key here, not just the ones that would have raised: which readers of a path call `glob()`
     * and which get away with a stat is not something an author configuring one can be expected to
     * know, and a key that reads as ignored today is a key that raises after the next refactor.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{key: string, path: string}>
     */
    public static function unholdable(array $config): array
    {
        $found = [];

        foreach (self::PATH_KEYS as $key => $shape) {
            $value = self::get($config, explode('.', $key));

            if ($shape === PathShape::Single) {
                if (is_string($value) && ConfinedPath::holdable($value) === null) {
                    $found[] = ['key' => $key, 'path' => $value];
                }

                continue;
            }

            foreach (is_array($value) ? $value : [] as $index => $entry) {
                $path = $shape === PathShape::TargetList
                    ? (is_array($entry) ? $entry['path'] ?? null : null)
                    : $entry;

                if (is_string($path) && ConfinedPath::holdable($path) === null) {
                    $found[] = [
                        'key' => $key.'.'.(is_int($index) ? (string) $index : $index),
                        'path' => $path,
                    ];
                }
            }
        }

        return $found;
    }

    /** Relativized when absolute and inside $basePath, untouched otherwise. */
    private static function rewrite(string $value, string $basePath): string
    {
        return Paths::relative($value, $basePath) ?? $value;
    }

    /** One export-target map with its `path` relativized. Anything else passes through untouched. */
    private static function rewriteTarget(mixed $entry, string $basePath): mixed
    {
        if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
            return $entry;
        }

        $entry['path'] = self::rewrite($entry['path'], $basePath);

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $segments
     */
    private static function get(array $config, array $segments): mixed
    {
        $cursor = $config;

        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $segments
     * @return array<string, mixed>
     */
    private static function set(array $config, array $segments, mixed $value): array
    {
        $segment = $segments[0];

        if (count($segments) === 1) {
            $config[$segment] = $value;

            return $config;
        }

        /** @var array<string, mixed> $nested */
        $nested = is_array($config[$segment] ?? null) ? $config[$segment] : [];
        $config[$segment] = self::set($nested, array_slice($segments, 1), $value);

        return $config;
    }
}
