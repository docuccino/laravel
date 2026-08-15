<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;

/**
 * Parses a `throttle` middleware string into a {@see ThrottleLimit}. Only one distinction is left to
 * draw — a named limiter (`throttle:api`) against an inline allowance (`throttle:60,1`, `throttle:60`,
 * `throttle:60,0.5`, bare `throttle`, the guest|authenticated `throttle:10|60`) — but drawing it still
 * takes the whole parameter grammar, or an inline form gets mistaken for a limiter nobody registered.
 * Also handles the `ThrottleRequests::using()`/`::with()` FQCN forms, including the Redis variant. Null
 * for anything that isn't a throttle.
 */
final class ThrottleParser
{
    public function parse(string $middleware): ?ThrottleLimit
    {
        $parameters = $this->parametersFor($middleware);
        if ($parameters === null) {
            return null;
        }

        // No arguments: Laravel's middleware defaults, 60 per minute — an allowance, not a name.
        if ($parameters === '') {
            return new ThrottleLimit;
        }

        $first = trim(explode(',', $parameters)[0]);

        // Pipe form `10|60` is guest|authenticated allowances — see Laravel's resolveMaxAttempts. Never a
        // limiter name: the lookup uses the whole string, and nobody registers one containing a pipe.
        if (str_contains($first, '|')) {
            return new ThrottleLimit;
        }

        return ctype_digit($first) ? new ThrottleLimit : new ThrottleLimit(name: $first);
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
}
