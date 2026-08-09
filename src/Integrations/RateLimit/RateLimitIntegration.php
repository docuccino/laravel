<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

/**
 * Entry point for the rate-limiting integration. Always on: `throttle` ships with Laravel, so there's no
 * package to guard on.
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
            RateLimiterDigestContributor::class,
        ];
    }
}
