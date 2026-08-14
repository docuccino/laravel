<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

/**
 * Builds the `429 Too Many Requests` response: the `Retry-After` and `X-RateLimit-*` headers Laravel's
 * ThrottleRequests middleware sets, plus Laravel's stock JSON `{message}` body. A known numeric throttle
 * puts concrete numbers in the header examples; an unfolded named limiter documents the headers without
 * values. Pure, so the header shape is dataset-testable.
 *
 * The `{message}` body is only the fallback: the extension prefers whatever the error-response chain
 * documents for a throttle exception, so an app with its own error shape doesn't get a contradictory 429.
 */
final class RateLimitResponse
{
    /**
     * @return array<string, mixed>
     */
    public function build(ThrottleLimit $limit): array
    {
        $retryAfter = ['type' => 'integer'];
        $rateLimit = ['type' => 'integer'];

        if (! $limit->isNamed()) {
            $rateLimit['example'] = $limit->maxAttempts;
            $retryAfter['example'] = $limit->retryAfterSeconds();
        }

        $description = 'Too Many Requests — the rate limit for this endpoint has been exceeded.';
        if ($limit->guestMaxAttempts !== null) {
            $description .= sprintf(
                ' The documented limit applies to authenticated requests; unauthenticated requests are limited to %d per window.',
                $limit->guestMaxAttempts,
            );
        }

        return [
            'description' => $description,
            'headers' => [
                'Retry-After' => [
                    'description' => 'Seconds to wait before making another request.',
                    'schema' => $retryAfter,
                ],
                'X-RateLimit-Limit' => [
                    'description' => 'The maximum number of requests permitted in the current window.',
                    'schema' => $rateLimit,
                ],
                'X-RateLimit-Remaining' => [
                    'description' => 'The number of requests remaining in the current window.',
                    'schema' => ['type' => 'integer'],
                ],
                'X-RateLimit-Reset' => [
                    'description' => 'Unix timestamp (seconds, UTC) at which the current rate limit window resets.',
                    'schema' => ['type' => 'integer'],
                ],
            ],
            'content' => [
                'application/json' => [
                    'schema' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]],
                ],
            ],
        ];
    }
}
