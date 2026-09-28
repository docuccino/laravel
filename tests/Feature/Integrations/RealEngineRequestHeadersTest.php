<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * The half of the request-header producer the stub engine cannot prove: that the real engine types each
 * receiver as the request — an injected one, a FormRequest's `$this`, the `request()` helper, the facade —
 * descends from the action into the FormRequest method it calls, and does NOT type a response as one.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('publishes the headers an action and its FormRequest read, from every receiver the framework offers', function (): void {
    $stored = FixtureRunner::requestHeaders(
        'app/Http/Controllers/RequestHeaderController.php',
        'App\\Http\\Controllers\\RequestHeaderController',
        'store',
        'App\\Http\\Requests\\PlaceOrderRequest',
    );

    // `Idempotency-Key` is read in toInput(), which only the action calls; `X-Client-Channel` in
    // prepareForValidation(), which only the framework does. Both are optional strings: `header()` answers
    // null when the header is absent, and nothing here refuses the request for it. `X-Service-Token` is read
    // only by the gate — its absence IS the refusal — so it is no optional input and is not published.
    expect($stored['headers'])->toBe([
        'Idempotency-Key' => ['required' => false, 'type' => 'string'],
        'X-Client-Channel' => ['required' => false, 'type' => 'string'],
    ])
        // The FormRequest's file is what the answer was read from, so editing it has to rebuild the route.
        ->and($stored['files'])->toContain('PlaceOrderRequest.php');

    $traced = FixtureRunner::requestHeaders(
        'app/Http/Controllers/RequestHeaderController.php',
        'App\\Http\\Controllers\\RequestHeaderController',
        'trace',
    );

    // `X_Tenant` is looked up as `x-tenant` by the header bag, so that is what a client sends; `\Request` is
    // the facade's default alias; `X-Forwarded-For` is a proxy's to set, not a client's; and the response's
    // `header('X-Served-By', …)` sets a header and reads none.
    expect(array_keys($traced['headers']))->toBe(['If-None-Match', 'X-Request-Id', 'X-Tenant', 'X-Trace-Parent']);
})->group('fixture');
