<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

/**
 * The limit {@see RateLimiterLimitVisitor} folds out of a named limiter's `RateLimiter::for` closure. A
 * mutable accumulator: the visitor writes into it while inspecting the returns, the extension reads
 * {@see resolved()} once the trace comes back. See the visitor for what does and doesn't fold.
 */
final class RateLimiterLimit
{
    /** Return expressions handed to the visitor; more than one means conditional, so bail. */
    public int $returnsSeen = 0;

    /** Set once something disqualifying was seen. */
    public bool $bailed = false;

    public ?int $maxAttempts = null;

    /** Window length in seconds; `Retry-After` derives from it. */
    public ?int $decaySeconds = null;

    /** Exactly one clean limit folded and nothing disqualified it. */
    public function resolved(): bool
    {
        return ! $this->bailed
            && $this->returnsSeen === 1
            && $this->maxAttempts !== null
            && $this->decaySeconds !== null;
    }
}
