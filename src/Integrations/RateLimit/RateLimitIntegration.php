<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

/**
 * Entry point for the rate-limiting integration (design §Phase 4). Always-on — `throttle` middleware
 * ships with Laravel, so there is no package to guard on; the service provider spreads
 * {@see extensions()} into the default set unconditionally.
 */
final class RateLimitIntegration
{
    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            RateLimitResponsesExtension::class,
            // Environment-digest seam (A4): the RateLimiter::for registration set feeds the document-level
            // fragment-cache digest so registering a named limiter refreshes a numberless-429 fragment.
            RateLimiterDigestContributor::class,
        ];
    }
}
