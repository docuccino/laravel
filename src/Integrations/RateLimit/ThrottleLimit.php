<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

/**
 * A parsed `throttle` declaration. One question survives about one: does it name a `RateLimiter::for`
 * limiter (`throttle:api`), or state its allowance inline (`throttle:60,1`, `throttle:10|60`, bare
 * `throttle`)? The documented 429 is identical either way — value-free, see {@see RateLimitResponse} —
 * so the name is only ever used to check that something registered it.
 */
final readonly class ThrottleLimit
{
    public function __construct(public ?string $name = null) {}
}
