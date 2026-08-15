<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Illuminate\Cache\RateLimiter;
use ReflectionObject;
use Throwable;

/**
 * Feeds the NAMES of the app's `RateLimiter::for` registrations into the environment digest (design §10),
 * catching what per-fragment dependency hashes can't: a route carrying `throttle:api` while `api` is
 * unregistered records that diagnostic in its fragment and depends on no file of its own, so registering
 * the limiter afterwards would never refresh the warm fragment.
 *
 * The name set is the whole input — what a limiter's closure says never reaches the document, so editing
 * a limiter's rate invalidates nothing. Names are sorted; an unreadable limiter set contributes the
 * empty string.
 */
final class RateLimiterDigestContributor implements EnvironmentDigestContributor
{
    public function __construct(private readonly RateLimiter $limiters) {}

    public function digest(): string
    {
        try {
            $reflection = new ReflectionObject($this->limiters);
            if (! $reflection->hasProperty('limiters')) {
                return 'rate-limiters:';
            }

            $value = $reflection->getProperty('limiters')->getValue($this->limiters);
            if (! is_array($value)) {
                return 'rate-limiters:';
            }

            $names = array_map(strval(...), array_keys($value));
            sort($names);

            return 'rate-limiters:'.implode(',', $names);
        } catch (Throwable) {
            return '';
        }
    }
}
