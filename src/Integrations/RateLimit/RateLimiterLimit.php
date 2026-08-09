<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

/**
 * The limit a {@see RateLimiterLimitVisitor} folds out of a named rate limiter's `RateLimiter::for`
 * closure. A mutable accumulator: the visitor writes into it as it inspects the closure's return(s),
 * and the extension reads {@see resolved()} back once the trace returns.
 *
 * Recovery succeeds only for a SINGLE unconditional `return Limit::per*(…)` of literal-int arguments;
 * anything else (multiple/conditional returns, a non-literal argument, `Limit::none()`, an array of
 * limits, or a `->response(…)` custom-body chain) sets {@see $bailed}, and the extension keeps its
 * numberless-429 + diagnostic floor.
 */
final class RateLimiterLimit
{
    /** Number of return expressions the visitor has been handed (multiple ⇒ conditional ⇒ bail). */
    public int $returnsSeen = 0;

    /** Set when something disqualifying was seen — recovery must not proceed. */
    public bool $bailed = false;

    public ?int $maxAttempts = null;

    /** The window length in seconds (per-second → 1, per-hour → 3600, …); Retry-After derives from it. */
    public ?int $decaySeconds = null;

    /** True when exactly one clean limit folded and nothing disqualified it. */
    public function resolved(): bool
    {
        return ! $this->bailed
            && $this->returnsSeen === 1
            && $this->maxAttempts !== null
            && $this->decaySeconds !== null;
    }
}
