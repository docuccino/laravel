<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\RateLimit\ThrottleParser;

/**
 * Dataset coverage over every `throttle` middleware form plus the non-throttle degradation (the map is
 * the parse table; one form is not coverage). The classification is load-bearing in one direction only:
 * anything misread as a NAME raises `rate-limit.unregistered-limiter` against a limiter that was never
 * meant to exist — so every inline-allowance form has to come back nameless.
 */
it('tells a named limiter from an inline allowance, for each throttle form', function (string $middleware, ?string $name): void {
    $limit = (new ThrottleParser)->parse($middleware);

    expect($limit)->not->toBeNull()
        ->and($limit->name)->toBe($name);
})->with([
    // Inline allowances — never a limiter name.
    'numeric with decay' => ['throttle:60,1', null],
    'numeric without decay' => ['throttle:100', null],
    'numeric with wider window' => ['throttle:30,5', null],
    'float decay' => ['throttle:60,0.5', null],
    'bare throttle (the middleware default)' => ['throttle', null],
    'guest|authenticated pipe form' => ['throttle:10|60', null],
    'guest|authenticated pipe form with decay' => ['throttle:10|60,1', null],
    // A pipe side may name a property on the user rather than a number (Laravel's resolveMaxAttempts);
    // the lookup uses the whole string, and nobody registers a limiter with a pipe in its name.
    'pipe form naming a user attribute' => ['throttle:10|rate_limit', null],
    'FQCN ::with() numeric' => ['Illuminate\Routing\Middleware\ThrottleRequests:60,1', null],
    'FQCN bare' => ['Illuminate\Routing\Middleware\ThrottleRequests', null],
    'FQCN redis variant numeric' => ['Illuminate\Routing\Middleware\ThrottleRequestsWithRedis:120,2', null],
    // Named limiters.
    'named limiter' => ['throttle:api', 'api'],
    'named limiter with a trailing argument' => ['throttle:api,extra', 'api'],
    'named limiter with surrounding space' => ['throttle: api ', 'api'],
    'FQCN ::using() named' => ['Illuminate\Routing\Middleware\ThrottleRequests:api', 'api'],
    'FQCN redis variant named' => ['Illuminate\Routing\Middleware\ThrottleRequestsWithRedis:uploads', 'uploads'],
]);

it('returns null for a non-throttle middleware', function (string $middleware): void {
    expect((new ThrottleParser)->parse($middleware))->toBeNull();
})->with([
    'auth' => ['auth:sanctum'],
    'unrelated' => ['bindings'],
    'throttle-like prefix' => ['throttler:60'],
]);
