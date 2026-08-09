<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

/**
 * A parsed `throttle` declaration, either numeric (`throttle:60,1`) or named (`throttle:api`, its limit
 * defined by a `RateLimiter::for` closure). A named limiter the engine manages to fold becomes numeric and
 * carries {@see $decaySeconds}, since per-second/hour/day windows don't fit the middleware's whole-minute
 * {@see $decayMinutes}; one it can't fold stays named and documents the 429 without numbers.
 */
final readonly class ThrottleLimit
{
    public function __construct(
        public ?int $maxAttempts = null,
        public ?float $decayMinutes = null,
        public ?string $name = null,
        public ?int $guestMaxAttempts = null,
        public ?int $decaySeconds = null,
    ) {}

    public function isNamed(): bool
    {
        return $this->name !== null;
    }

    /**
     * The `Retry-After` example, in whole seconds. A folded named limiter has its window directly; the
     * inline form derives it from the (possibly fractional) minute decay.
     */
    public function retryAfterSeconds(): int
    {
        return $this->decaySeconds ?? (int) round(($this->decayMinutes ?? 1.0) * 60);
    }
}
