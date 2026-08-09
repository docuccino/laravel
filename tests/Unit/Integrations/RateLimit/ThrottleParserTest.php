<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\RateLimit\ThrottleParser;

/**
 * Dataset coverage over every `throttle` middleware form plus the non-throttle degradation (the map
 * is the parse table; one form is not coverage).
 */
it('parses each throttle middleware form', function (string $middleware, ?int $max, ?float $decay, ?string $name, ?int $guest): void {
    $limit = (new ThrottleParser)->parse($middleware);

    expect($limit)->not->toBeNull();
    expect($limit->maxAttempts)->toBe($max)
        ->and($limit->decayMinutes)->toBe($decay)
        ->and($limit->name)->toBe($name)
        ->and($limit->guestMaxAttempts)->toBe($guest)
        ->and($limit->isNamed())->toBe($name !== null);
})->with([
    'numeric with decay' => ['throttle:60,1', 60, 1.0, null, null],
    'numeric without decay defaults to 1 minute' => ['throttle:100', 100, 1.0, null, null],
    'numeric with wider window' => ['throttle:30,5', 30, 5.0, null, null],
    'named limiter' => ['throttle:api', null, null, 'api', null],
    'bare throttle is a concrete 60/min limit' => ['throttle', 60, 1.0, null, null],
    'float decay' => ['throttle:60,0.5', 60, 0.5, null, null],
    'guest|authenticated pipe form' => ['throttle:10|60,1', 60, 1.0, null, 10],
    'guest|authenticated pipe form without decay' => ['throttle:10|60', 60, 1.0, null, 10],
    'FQCN ::with() numeric' => ['Illuminate\Routing\Middleware\ThrottleRequests:60,1', 60, 1.0, null, null],
    'FQCN ::using() named' => ['Illuminate\Routing\Middleware\ThrottleRequests:api', null, null, 'api', null],
    'FQCN bare' => ['Illuminate\Routing\Middleware\ThrottleRequests', 60, 1.0, null, null],
    'FQCN redis variant numeric' => ['Illuminate\Routing\Middleware\ThrottleRequestsWithRedis:120,2', 120, 2.0, null, null],
]);

it('returns null for a non-throttle middleware', function (string $middleware): void {
    expect((new ThrottleParser)->parse($middleware))->toBeNull();
})->with([
    'auth' => ['auth:sanctum'],
    'unrelated' => ['bindings'],
    'throttle-like prefix' => ['throttler:60'],
]);
