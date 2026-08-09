<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

use Docuccino\Laravel\Integrations\Support\AuthGuardDrivers;

/**
 * Decides, from a route's gathered middleware, which Sanctum auth modes protect the operation
 * (design §Phase 4 — Sanctum auto-config). TOKEN mode is signalled by the `auth:sanctum` guard, the
 * bare `sanctum` alias, an `abilities:`/`ability:` ability middleware, OR any `auth:<guard>` whose
 * configured driver is `sanctum` (auth audit #8 — so a custom sanctum-driver guard like `auth:mobile`
 * is recognised). The stateful-frontend middleware (registered app-wide by `statefulApi()`) enables
 * STATEFUL/cookie mode — but only alongside an actual auth guard, since `statefulApi()` prepends the
 * stateful middleware to the WHOLE api group, so its bare presence on a public route (login, register)
 * must NOT document a cookie requirement. A dual-auth app exhibits both signals on the same route, so
 * this returns the SET of active modes rather than a single choice. The guard→driver map is resolved
 * in the extension and passed in, so this stays pure and dataset-tested.
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
        // Stateful mode is only real when an auth guard also protects the route — otherwise the
        // group-prepended stateful middleware would falsely secure every public api-group route.
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
            // Ability middleware (`abilities:`/`ability:` short aliases or the `::using()` FQCN forms)
            // only ever guards Sanctum tokens, so its presence implies token mode.
            foreach (['abilities:', 'ability:', self::CHECK_ABILITIES.':', self::CHECK_FOR_ANY_ABILITY.':'] as $prefix) {
                if (str_starts_with($entry, $prefix)) {
                    return true;
                }
            }
        }

        // A custom-named guard whose configured driver is `sanctum` (e.g. `auth:mobile`).
        return in_array('sanctum', AuthGuardDrivers::driversFor($middleware, $guardDrivers, $defaultGuard), true);
    }

    /**
     * Whether the route carries any authentication guard middleware (the gate for stateful mode).
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
