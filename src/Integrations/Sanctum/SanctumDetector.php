<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

use Docuccino\Laravel\Integrations\Support\AuthGuardDrivers;

/**
 * Works out which Sanctum auth modes protect a route from its gathered middleware. Token mode is
 * signalled by `auth:sanctum`, the bare `sanctum` alias, an `abilities:`/`ability:` middleware, or any
 * `auth:<guard>` whose configured driver is `sanctum` (so a custom `auth:mobile` is recognised). Stateful
 * cookie mode needs the stateful-frontend middleware *and* a real auth guard: `statefulApi()` prepends
 * that middleware to the whole api group, so on its own it would falsely secure public routes like login.
 *
 * Returns the set of modes, not one choice — dual-auth apps show both signals on the same route. The
 * guard→driver map is resolved in the extension and passed in, keeping this pure and dataset-testable.
 */
final class SanctumDetector
{
    public const TOKEN = 'token';

    public const STATEFUL = 'stateful';

    private const STATEFUL_MIDDLEWARE = 'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful';

    private const CHECK_ABILITIES = 'Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities';

    private const CHECK_FOR_ANY_ABILITY = 'Laravel\\Sanctum\\Http\\Middleware\\CheckForAnyAbility';

    /**
     * The modes the route itself supports, in a stable order (token before stateful).
     *
     * @param  list<string>  $middleware
     * @param  array<string, string>  $guardDrivers  guard name → driver, from `config('auth.guards')`
     * @return list<string>
     */
    public function supportedModes(array $middleware, array $guardDrivers = [], string $defaultGuard = 'web'): array
    {
        $modes = [];
        if ($this->hasTokenGuard($middleware, $guardDrivers, $defaultGuard)) {
            $modes[] = self::TOKEN;
        }
        if (in_array(self::STATEFUL_MIDDLEWARE, $middleware, true) && $this->hasAuthMiddleware($middleware, $guardDrivers, $defaultGuard)) {
            $modes[] = self::STATEFUL;
        }

        return $modes;
    }

    /**
     * @param  list<string>  $middleware
     * @param  array<string, string>  $guardDrivers
     */
    private function hasTokenGuard(array $middleware, array $guardDrivers, string $defaultGuard): bool
    {
        foreach ($middleware as $entry) {
            if ($entry === 'sanctum') {
                return true;
            }
            if (str_starts_with($entry, 'auth:') && in_array('sanctum', array_map('trim', explode(',', substr($entry, 5))), true)) {
                return true;
            }
            // Ability middleware (short aliases or the `::using()` FQCN forms) only ever guards Sanctum
            // tokens, so its presence implies token mode.
            foreach (['abilities:', 'ability:', self::CHECK_ABILITIES.':', self::CHECK_FOR_ANY_ABILITY.':'] as $prefix) {
                if (str_starts_with($entry, $prefix)) {
                    return true;
                }
            }
        }

        // A custom-named guard whose configured driver is `sanctum`.
        return in_array('sanctum', AuthGuardDrivers::driversFor($middleware, $guardDrivers, $defaultGuard), true);
    }

    /**
     * Any authentication guard middleware at all — the gate for stateful mode.
     *
     * @param  list<string>  $middleware
     * @param  array<string, string>  $guardDrivers
     */
    private function hasAuthMiddleware(array $middleware, array $guardDrivers, string $defaultGuard): bool
    {
        if ($this->hasTokenGuard($middleware, $guardDrivers, $defaultGuard)) {
            return true;
        }

        foreach ($middleware as $entry) {
            if ($entry === 'auth' || str_starts_with($entry, 'auth:') || str_starts_with($entry, 'auth.')) {
                return true;
            }
        }

        return false;
    }
}
