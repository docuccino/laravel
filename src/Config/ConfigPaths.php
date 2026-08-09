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
 * reported as a `config.machine-dependent-path` info diagnostic ({@see ConfigDiagnostics}).
 *
 * @internal
 */
final class ConfigPaths
{
    /**
     * Document-config keys holding filesystem paths, in a fixed order so diagnostics are
     * deterministic. `true` = a list of paths, `false` = a single path.
     *
     * Not here on purpose: the `viewer` bag, `servers[].url` and `integrations.passport.url` aren't
     * filesystem paths; `cache.path` and `engine.project_paths` are, but live outside the per-document
     * bag so they never reach a `configHash` or any emitted byte.
     *
     * @var array<string, bool>
     */
    private const PATH_KEYS = [
        'content.dir' => false,
        'export.path' => false,
        'info.description.file' => false,
        'overlays' => true,
    ];

    /**
     * $config with every {@see PATH_KEYS} value inside $basePath rewritten base-relative. Already
     * relative, outside the base path, or the wrong shape for its key: returned untouched.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function relativize(array $config, string $basePath): array
    {
        foreach (self::PATH_KEYS as $key => $isList) {
            $segments = explode('.', $key);
            $value = self::get($config, $segments);

            if ($isList) {
                if (! is_array($value)) {
                    continue;
                }

                $rewritten = [];
                $changed = false;
                foreach ($value as $index => $entry) {
                    $next = is_string($entry) ? self::rewrite($entry, $basePath) : $entry;
                    $changed = $changed || $next !== $entry;
                    $rewritten[$index] = $next;
                }

                if ($changed) {
                    $config = self::set($config, $segments, $rewritten);
                }

                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            $rewritten = self::rewrite($value, $basePath);
            if ($rewritten !== $value) {
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

        foreach (self::PATH_KEYS as $key => $isList) {
            $value = self::get($config, explode('.', $key));

            if ($isList) {
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

    /** Relativized when absolute and inside $basePath, untouched otherwise. */
    private static function rewrite(string $value, string $basePath): string
    {
        return Paths::relative($value, $basePath) ?? $value;
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
