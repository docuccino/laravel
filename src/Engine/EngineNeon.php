<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

use Docuccino\Laravel\Support\Paths;

/**
 * The application's own PHPStan config file, when `engine.neon` names one: the engine includes it in
 * the config it generates, so a project's existing extensions and stubs sharpen its documentation with
 * no Docuccino-specific API. Configured relative to the application base path (an absolute path is
 * taken as given).
 *
 * Everything that file registers can change any type the engine infers, so what it SAYS — not merely
 * where it lives — belongs in the fragment-cache key ({@see digest()}).
 *
 * @internal
 */
final class EngineNeon
{
    /**
     * The configured file, resolved against the base path — null when nothing is configured. A path
     * that names no file still comes back: the engine skips it and the build reports it, so exactly one
     * place decides what a missing file means.
     *
     * @param  array<string, mixed>  $engineConfig  the `docuccino.engine` bag
     */
    public static function path(array $engineConfig, string $basePath): ?string
    {
        $configured = $engineConfig['neon'] ?? null;

        return is_string($configured) && $configured !== ''
            ? Paths::absolute($configured, $basePath)
            : null;
    }

    /**
     * The file's content hash for the build fingerprint. Nothing configured — and a configured file
     * that isn't there — digest to the empty string; the configured path itself travels separately, in
     * the engine config bag, so adding, moving and removing the key all still move the key.
     *
     * @param  array<string, mixed>  $engineConfig  the `docuccino.engine` bag
     */
    public static function digest(array $engineConfig, string $basePath): string
    {
        $path = self::path($engineConfig, $basePath);
        $hash = $path === null ? false : @hash_file('sha256', $path);

        return $hash === false ? '' : $hash;
    }
}
