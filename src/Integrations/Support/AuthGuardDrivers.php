<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * Resolves a route's `auth`/`auth:<guard>` middleware to the auth DRIVERS behind those guards, using
 * the app's `config('auth.guards')` map (auth audit #8). Driver — not guard name — is the robust
 * signal for which security integration owns a route: a `passport`-driver guard is Passport
 * regardless of its name, and an `api` guard on a `token`/`sanctum` driver is not Passport. The
 * config is resolved in the extension (which may touch it) and the plain guard→driver map is passed
 * in, so this stays pure and dataset-testable.
 */
final class AuthGuardDrivers
{
    /**
     * The drivers the route's auth middleware resolve to, deduped in first-seen order. Bare `auth`
     * uses the default guard; an `auth:a,b` list names several guards. A guard absent from the map
     * (unknown / unconfigured) contributes no driver.
     *
     * @param  list<string>  $middleware
     * @param  array<string, string>  $drivers  guard name → driver
     * @return list<string>
     */
    public static function driversFor(array $middleware, array $drivers, string $defaultGuard): array
    {
        $result = [];
        foreach ($middleware as $entry) {
            foreach (self::guardsFor($entry, $defaultGuard) as $guard) {
                $driver = $drivers[$guard] ?? null;
                if ($driver !== null && ! in_array($driver, $result, true)) {
                    $result[] = $driver;
                }
            }
        }

        return $result;
    }

    /**
     * Build the guard→driver map from a raw `config('auth.guards')` value, dropping malformed entries.
     *
     * @return array<string, string>
     */
    public static function map(mixed $guards): array
    {
        if (! is_array($guards)) {
            return [];
        }

        $map = [];
        foreach ($guards as $name => $config) {
            if (is_string($name) && is_array($config) && isset($config['driver']) && is_string($config['driver'])) {
                $map[$name] = $config['driver'];
            }
        }

        return $map;
    }

    /**
     * The guard names an auth middleware entry references: the default guard for bare `auth`, the
     * comma-listed guards for `auth:a,b`, and none for any non-`auth` middleware.
     *
     * @return list<string>
     */
    private static function guardsFor(string $entry, string $defaultGuard): array
    {
        if ($entry === 'auth') {
            return [$defaultGuard];
        }

        if (str_starts_with($entry, 'auth:')) {
            return array_values(array_filter(
                array_map('trim', explode(',', substr($entry, 5))),
                static fn (string $guard): bool => $guard !== '',
            ));
        }

        return [];
    }

    /**
     * The app's default auth guard for resolving a bare `auth` middleware, from a raw
     * `config('auth.defaults.guard')` value — Laravel's own default is `web` when unset/blank.
     */
    public static function defaultGuard(mixed $configured): string
    {
        return is_string($configured) && $configured !== '' ? $configured : 'web';
    }
}
