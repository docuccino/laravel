<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\RateLimit\RateLimitResponse;

it('documents every rate-limit header by meaning, with no example', function (string $header, string $description): void {
    // `required` on every one of them: ThrottleRequests builds the 429 through a single branchless path,
    // so a document that called any of the four optional would publish a weaker contract than the server's.
    $response = (new RateLimitResponse)->build();

    expect($response['headers'])->toHaveKeys(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset']);
    expect($response['headers'][$header])->toBe([
        'description' => $description,
        'required' => true,
        'schema' => ['type' => 'integer'],
    ]);
})->with([
    'Retry-After' => ['Retry-After', 'Seconds to wait before making another request.'],
    'X-RateLimit-Limit' => ['X-RateLimit-Limit', 'The maximum number of requests permitted in the current window.'],
    'X-RateLimit-Remaining' => ['X-RateLimit-Remaining', 'The number of requests remaining in the current window.'],
    'X-RateLimit-Reset' => ['X-RateLimit-Reset', 'Unix timestamp (seconds, UTC) at which the current rate limit window resets.'],
]);

it('describes the limit without asserting one', function (): void {
    // No `example` and no limit interpolated into the description — the guest|authenticated form used to
    // add "unauthenticated requests are limited to N per window", which split otherwise-identical 429s.
    $response = (new RateLimitResponse)->build();

    expect($response['description'])->toBe('Too Many Requests — the rate limit for this endpoint has been exceeded.')
        ->and(json_encode($response))->not->toContain('example');
});

it('falls back to Laravel\'s stock {message} body', function (): void {
    $response = (new RateLimitResponse)->build();

    expect($response['content']['application/json']['schema']['properties'])->toHaveKey('message');
});
