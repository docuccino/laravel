<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Real-engine proof (design §Phase 4 — rate limiting, Wave D item 4) that the engine's closure trace
 * folds a named limiter's `RateLimiter::for` closure to concrete numbers. The fixture app registers
 * limiters in their idiomatic shapes (the Laravel-11 skeleton's arrow `api` limiter, a full-closure
 * `uploads` limiter, a conditional `dynamic` one) and this points the REAL PhpStan engine at each
 * closure by line — the same location the RateLimit integration reaches via `ReflectionFunction`.
 * Fixture honesty: the shapes are real limiters, not ones bent to satisfy the folder — the `dynamic`
 * one is pinned to its numberless degradation.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The start line of the `RateLimiter::for('<name>', …)` closure in the fixture provider. */
function limiterLine(string $name): int
{
    $source = file_get_contents(FixtureRunner::path('app/Providers/AppServiceProvider.php'));
    $lines = $source === false ? [] : explode("\n", $source);
    foreach ($lines as $index => $line) {
        if (str_contains($line, "RateLimiter::for('".$name."'")) {
            return $index + 1;
        }
    }

    throw new RuntimeException("limiter '{$name}' not found in the fixture provider");
}

it('folds the idiomatic arrow api limiter to 60 per minute on the real engine', function (): void {
    $result = FixtureRunner::traceRateLimiter('app/Providers/AppServiceProvider.php', limiterLine('api'));

    expect($result['resolved'])->toBeTrue()
        ->and($result['maxAttempts'])->toBe(60)
        ->and($result['decaySeconds'])->toBe(60);
})->group('fixture');

it('folds a full-closure per-hour limiter to 100 per 3600s on the real engine', function (): void {
    $result = FixtureRunner::traceRateLimiter('app/Providers/AppServiceProvider.php', limiterLine('uploads'));

    expect($result['resolved'])->toBeTrue()
        ->and($result['maxAttempts'])->toBe(100)
        ->and($result['decaySeconds'])->toBe(3600);
})->group('fixture');

it('leaves a conditional limiter numberless on the real engine', function (): void {
    $result = FixtureRunner::traceRateLimiter('app/Providers/AppServiceProvider.php', limiterLine('dynamic'));

    expect($result['resolved'])->toBeFalse()
        ->and($result['bailed'])->toBeTrue()
        ->and($result['maxAttempts'])->toBeNull();
})->group('fixture');
