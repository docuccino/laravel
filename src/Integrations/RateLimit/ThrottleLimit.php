<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

/**
 * A parsed `throttle` middleware declaration. Two shapes: a numeric limit (`throttle:60,1` →
 * {@see $maxAttempts}/{@see $decayMinutes} known) whose numbers document the rate headers, or a
 * named limiter (`throttle:api` → {@see $name}) whose limit is defined by a `RateLimiter::for`
 * closure. A named limiter whose closure the engine could fold to a single `Limit::per*(…)` becomes
 * a numeric limit carrying {@see $decaySeconds} (per-second/hour/day windows do not fit the
 * middleware's whole-minute {@see $decayMinutes}); one it could not stays named and documents the
 * 429 without numbers.
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
     * The `Retry-After` example in whole seconds: a folded named limiter carries its window directly
     * in {@see $decaySeconds}; the inline `throttle:60,1` form derives it from the whole-/fractional-
     * minute decay (unchanged, so the numeric-throttle output stays byte-identical).
     */
    public function retryAfterSeconds(): int
    {
        return $this->decaySeconds ?? (int) round(($this->decayMinutes ?? 1.0) * 60);
    }
}
