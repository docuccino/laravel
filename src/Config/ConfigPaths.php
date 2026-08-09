<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Laravel\Registry\ConfigDiagnostics;
use Docuccino\Laravel\Support\Paths;

/**
 * The single owner of "which document-config keys hold filesystem paths", and of the base-path
 * relativisation every one of them goes through at config-read time.
 *
 * Why relativise at all: {@see DocumentConfig::hash()} digests the
 * whole raw config bag, and that digest is EMITTED into the document as `document.configHash`. An
 * absolute path in config therefore folds the generating machine's filesystem layout into a committed
 * artifact — two checkouts of the same code at different paths would emit different bytes, breaking
 * the determinism promise. Rewriting every in-app absolute path to its base-relative form makes the
 * hash depend on the path's MEANING rather than on how the user happened to spell it: `content.dir`
 * written as `base_path('resources/docs/api')` and as `'resources/docs/api'` hash identically.
 *
 * Resolution is unaffected: every consumer resolves a relative value against the app base path
 * already ({@see Paths::absolute()}, {@see ConfinedPath::resolve()}), so the
 * rewritten value points at the very same file.
 *
 * A path that genuinely lives OUTSIDE the app is kept exactly as configured — silently rewriting it
 * would break the read — and surfaces as a `config.machine-dependent-path` info diagnostic
 * ({@see ConfigDiagnostics}) so the machine dependence is stated rather
 * than hidden.
 *
 * @internal
 */
final class ConfigPaths
{
    /**
     * Every document-config key whose value is a filesystem path, in a fixed order (deterministic
     * diagnostics). `true` marks a key holding a LIST of paths, `false` a single path.
     *
     * Deliberately absent, and audited as such: the whole `viewer` bag (`route` is a URL path, not a
     * filesystem one; `gate`/`source`/`driver`/`middleware` are names and keywords), `servers[].url`
     * and `integrations.passport.url` (URLs), and the app-level `cache.path` / `engine.project_paths`
     * — those two are filesystem paths but sit outside the per-document bag, so they never reach a
     * document's `configHash` or any emitted byte.
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
     * $config with every {@see PATH_KEYS} value that sits inside $basePath rewritten to its
     * base-relative form. Values already relative, values outside the base path, and values of any
     * other shape than the key's declared one are returned untouched.
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
     * The path-like keys of an ALREADY-relativized config whose value is still absolute — i.e. points
     * outside the app base path, so it is machine-dependent. Each entry names the dotted config key
     * and the offending path, in {@see PATH_KEYS} order.
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

    /** One path value: relativized when it is absolute and inside $basePath, untouched otherwise. */
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
