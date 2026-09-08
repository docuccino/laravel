<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Laravel\Support\AuthMiddlewareNames;

/**
 * Resolves a route's `auth`/`auth:<guard>` middleware — in either spelling, alias or class name — to the
 * DRIVERS behind those guards, via the app's
 * `config('auth.guards')` map. The driver, not the guard name, is what tells you which security
 * integration owns a route: a `passport`-driver guard is Passport whatever it's called, and an `api`
 * guard on a `sanctum` driver isn't. The extension resolves the config and passes the plain map in, so
 * this stays pure and dataset-testable.
 */
final class AuthGuardDrivers
{
    /**
     * The drivers the middleware resolve to, deduped in first-seen order — which is the only dedupe
     * there is to do, since a guard named twice resolves to one driver either way. A guard missing from
     * the map contributes nothing.
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
     * The guard→driver map from a raw `config('auth.guards')` value, malformed entries dropped.
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
     * The guards an entry names: the comma list for `auth:a,b`, and the default guard wherever the
     * entry names none — none otherwise.
     *
     * An EMPTY guard name is the default guard, which is the framework's own rule:
     * `AuthManager::guard()` opens with `$name = $name ?: $this->getDefaultDriver()`, so `auth`,
     * `auth:` and the trailing name in `auth:api,` all authenticate against
     * `config('auth.defaults.guard')` at runtime. Reading `auth:` as naming nothing left the route with
     * its 401 and no integration claiming it, so a Sanctum or Passport scheme the server really does
     * enforce went unpublished.
     *
     * Surrounding whitespace is a widening rather than that rule: the framework's pipeline splits the
     * parameter list untrimmed and `guard(' web')` throws, so a route written `auth:web, api` errors
     * instead of authenticating and no document is right about it. The author's evident intent is the
     * more useful of two answers about a route that cannot answer at all.
     *
     * @return list<string>
     */
    private static function guardsFor(string $entry, string $defaultGuard): array
    {
        // Both spellings of the authenticator, read through the one list of them ({@see
        // AuthMiddlewareNames}): a `passport` guard written `Authenticate::using('api')` names the same
        // guard `auth:api` does.
        $arguments = AuthMiddlewareNames::guardArguments($entry);
        if ($arguments === null) {
            return [];
        }

        $guards = [];
        foreach (explode(',', $arguments) as $guard) {
            $guard = trim($guard);
            $guards[] = $guard === '' ? $defaultGuard : $guard;
        }

        return $guards;
    }

    /** The default guard from `config('auth.defaults.guard')`; Laravel's own fallback is `web`. */
    public static function defaultGuard(mixed $configured): string
    {
        return is_string($configured) && $configured !== '' ? $configured : 'web';
    }
}
