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
 *
 * All four are `required`. ThrottleRequests builds the 429 through one branchless path — `buildException()`
 * asks `getHeaders()` with a non-null retry-after and no response to compare against, so the limit pair and
 * the retry pair are both always written — and Laravel's handler renders an HttpException with the headers
 * it carries. A header the server always sends and the document calls optional is a contract weaker than
 * the server's, and a generated client cannot type it non-optional.
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
                    'required' => true,
                    'schema' => ['type' => 'integer'],
                ],
                'X-RateLimit-Limit' => [
                    'description' => 'The maximum number of requests permitted in the current window.',
                    'required' => true,
                    'schema' => ['type' => 'integer'],
                ],
                'X-RateLimit-Remaining' => [
                    'description' => 'The number of requests remaining in the current window.',
                    'required' => true,
                    'schema' => ['type' => 'integer'],
                ],
                'X-RateLimit-Reset' => [
                    'description' => 'Unix timestamp (seconds, UTC) at which the current rate limit window resets.',
                    'required' => true,
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
