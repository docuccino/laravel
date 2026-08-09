<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

use Closure;
use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Illuminate\Cache\RateLimiter;
use ReflectionFunction;
use ReflectionObject;
use Throwable;

/**
 * Feeds the app's `RateLimiter::for` registration set into the environment digest (design §10), catching
 * what per-fragment dependency hashes can't: a route carrying `throttle:api` while `api` is unregistered
 * documents the numberless 429 floor and records no closure dependency, so registering the limiter
 * afterwards would never refresh that warm fragment. Each limiter contributes its name plus the closure's
 * source location, sorted by name. An unreflectable limiter set contributes the empty string.
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

            $records = [];
            foreach ($value as $name => $closure) {
                $records[(string) $name] = $this->fingerprint($closure);
            }
            ksort($records);

            $parts = [];
            foreach ($records as $name => $fingerprint) {
                $parts[] = $name.'@'.$fingerprint;
            }

            return 'rate-limiters:'.implode(',', $parts);
        } catch (Throwable) {
            return '';
        }
    }

    /** The registered limiter closure's source location, or empty when it cannot be reflected. */
    private function fingerprint(mixed $closure): string
    {
        if (! $closure instanceof Closure) {
            return '';
        }

        try {
            $function = new ReflectionFunction($closure);
            $file = $function->getFileName();
            $line = $function->getStartLine();

            return $file === false || $line === false ? '' : $file.':'.$line;
        } catch (Throwable) {
            return '';
        }
    }
}
