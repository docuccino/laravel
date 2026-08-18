<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

/**
 * Builds the `429 Too Many Requests` response: the `Retry-After` and `X-RateLimit-*` headers Laravel's
 * ThrottleRequests middleware sets, plus Laravel's stock JSON `{message}` body. Pure and value-free —
 * the same bytes for every throttled route, so the header shape is dataset-testable.
 *
 * The headers say what they MEAN, never what this route's limit happens to be. A documented number
 * duplicates what the app's own prose says, goes stale the moment somebody edits the middleware, and
 * makes a rate-limit tweak rewrite bytes — firing the semantic diff — across operations whose contract
 * did not change. It also splits an otherwise-identical 429 into a component per distinct limit, instead
 * of the one `TooManyRequests` every throttled route shares. The live response headers are authoritative, and a client learns the real numbers from
 * its first call. An app that genuinely wants a number in its published spec can state one in an overlay.
 *
 * The `{message}` body is only the fallback: the extension prefers whatever the error-response chain
 * documents for a throttle exception, so an app with its own error shape doesn't get a contradictory 429.
 */
final class RateLimitResponse
{
    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'description' => 'Too Many Requests — the rate limit for this endpoint has been exceeded.',
            'headers' => [
                'Retry-After' => [
                    'description' => 'Seconds to wait before making another request.',
                    'schema' => ['type' => 'integer'],
                ],
                'X-RateLimit-Limit' => [
                    'description' => 'The maximum number of requests permitted in the current window.',
                    'schema' => ['type' => 'integer'],
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
