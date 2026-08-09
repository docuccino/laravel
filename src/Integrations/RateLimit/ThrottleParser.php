<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;

/**
 * Parses a route's `throttle` middleware string into a {@see ThrottleLimit} (design §Phase 4 — rate
 * limiting): `throttle:60,1` → numeric limit (60 attempts / 1 minute), `throttle:60` → 60 / default
 * 1 minute, `throttle:api` → named limiter `api`. Also recognises the `ThrottleRequests::using()`/
 * `::with()` FQCN forms (`Illuminate\Routing\Middleware\ThrottleRequests:...`, incl. the Redis
 * variant), the guest|authenticated pipe form (`throttle:10|60`), and float decay (`throttle:60,0.5`).
 * Anything that is not a throttle declaration returns null.
 */
final class ThrottleParser
{
    public function parse(string $middleware): ?ThrottleLimit
    {
        $parameters = $this->parametersFor($middleware);
        if ($parameters === null) {
            return null;
        }

        if ($parameters === '') {
            // Bare `throttle` / `ThrottleRequests::with()` with no arguments: Laravel's middleware
            // defaults are 60 attempts / 1 minute (a concrete limit, not a named limiter).
            return new ThrottleLimit(maxAttempts: 60, decayMinutes: 1.0);
        }

        $parts = explode(',', $parameters);
        $first = trim($parts[0]);

        // Pipe form `10|60`: guest|authenticated attempt allowances (Laravel's resolveMaxAttempts).
        if (str_contains($first, '|')) {
            [$guest, $auth] = array_pad(explode('|', $first, 2), 2, '');
            $guest = trim($guest);
            $auth = trim($auth);
            if (ctype_digit($guest) && ctype_digit($auth)) {
                return new ThrottleLimit(
                    maxAttempts: (int) $auth,
                    decayMinutes: $this->decay($parts),
                    guestMaxAttempts: (int) $guest,
                );
            }

            return new ThrottleLimit(name: $first);
        }

        if (! ctype_digit($first)) {
            return new ThrottleLimit(name: $first);
        }

        return new ThrottleLimit(maxAttempts: (int) $first, decayMinutes: $this->decay($parts));
    }

    /**
     * Returns the parameter string for a throttle declaration (`''` for a bare declaration), or null
     * when the middleware is not a throttle at all. Recognises the short `throttle` alias and both
     * FQCN class names the `::using()`/`::with()` helpers render.
     */
    private function parametersFor(string $middleware): ?string
    {
        foreach (['throttle', ThrottleRequests::class, ThrottleRequestsWithRedis::class] as $prefix) {
            if ($middleware === $prefix) {
                return '';
            }
            if (str_starts_with($middleware, $prefix.':')) {
                return substr($middleware, strlen($prefix) + 1);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $parts
     */
    private function decay(array $parts): float
    {
        if (! isset($parts[1])) {
            return 1.0;
        }

        $raw = trim($parts[1]);

        return is_numeric($raw) ? (float) $raw : 1.0;
    }
}
