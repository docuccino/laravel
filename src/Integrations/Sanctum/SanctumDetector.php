<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

use Closure;
use Docuccino\Laravel\Integrations\Support\AuthGuardDrivers;
use Docuccino\Laravel\Support\AuthMiddlewareNames;

/**
 * Works out which Sanctum auth modes protect a route from its gathered middleware. Token mode is
 * signalled by `auth:sanctum`, the bare `sanctum` alias, an `abilities:`/`ability:` middleware, or any
 * `auth:<guard>` whose configured driver is `sanctum` (so a custom `auth:mobile` is recognised). The guard
 * is the authenticator's parameters ({@see AuthMiddlewareNames::guardArguments()}), read by class, so
 * `Authenticate::using()` and an application's own alias for an authenticator answer exactly as `auth`
 * does. Stateful cookie mode needs the stateful-frontend middleware *and* an
 * authenticator: `statefulApi()` prepends that middleware to the whole api group, so on its own it would
 * falsely secure public routes like login.
 *
 * Returns the set of modes, not one choice — dual-auth apps show both signals on the same route. The
 * guard→driver map, each entry's guard arguments and whether the route authenticates are resolved in the
 * extension and passed in, keeping this pure and dataset-testable.
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
     * @param  Closure(): bool  $authenticates  whether the route runs an authenticator, asked only where
     *                                          the stateful middleware is present
     * @param  Closure(string): ?string  $guardArguments  an entry's guard arguments, by class
     * @return list<string>
     */
    public function supportedModes(array $middleware, array $guardDrivers, string $defaultGuard, Closure $authenticates, Closure $guardArguments): array
    {
        $modes = [];
        $token = $this->hasTokenGuard($middleware, $guardDrivers, $defaultGuard, $guardArguments);
        if ($token) {
            $modes[] = self::TOKEN;
        }
        if (in_array(self::STATEFUL_MIDDLEWARE, $middleware, true) && ($token || $authenticates())) {
            $modes[] = self::STATEFUL;
        }

        return $modes;
    }

    /**
     * @param  list<string>  $middleware
     * @param  array<string, string>  $guardDrivers
     * @param  Closure(string): ?string  $guardArguments
     */
    private function hasTokenGuard(array $middleware, array $guardDrivers, string $defaultGuard, Closure $guardArguments): bool
    {
        foreach ($middleware as $entry) {
            if ($entry === 'sanctum') {
                return true;
            }
            $guards = $guardArguments($entry);
            if ($guards !== null && in_array('sanctum', array_map('trim', explode(',', $guards)), true)) {
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
        return in_array('sanctum', AuthGuardDrivers::driversFor($middleware, $guardDrivers, $defaultGuard, $guardArguments), true);
    }
}
