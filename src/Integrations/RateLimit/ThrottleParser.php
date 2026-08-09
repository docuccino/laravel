<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;

/**
 * Parses a `throttle` middleware string into a {@see ThrottleLimit}: `throttle:60,1` → 60 attempts per
 * minute, `throttle:60` → 60 per the default 1 minute, `throttle:api` → the named limiter `api`. Also
 * handles the `ThrottleRequests::using()`/`::with()` FQCN forms (including the Redis variant), the
 * guest|authenticated pipe form `throttle:10|60`, and float decay `throttle:60,0.5`. Null for anything
 * that isn't a throttle.
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
            // No arguments: Laravel's middleware defaults, 60 per minute — a concrete limit, not a name.
            return new ThrottleLimit(maxAttempts: 60, decayMinutes: 1.0);
        }

        $parts = explode(',', $parameters);
        $first = trim($parts[0]);

        // Pipe form `10|60` is guest|authenticated allowances — see Laravel's resolveMaxAttempts.
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
     * The parameter string for a throttle declaration (`''` when bare), or null when it isn't one at all.
     * Covers the short alias and both FQCNs the `::using()`/`::with()` helpers render.
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
