<?php

declare(strict_types=1);

/**
 * `docuccino/laravel` and `docuccino/inference-phpstan` are SIBLINGS, not a chain: the adapter requires
 * core and Illuminate, and treats the engine as an optional dev-only install (probed by string through
 * `Engine\EnginePackage`). These rules keep it that way — a single `use Docuccino\Inference\…` or
 * `use PHPStan\…` would fatal a production app that only requires the adapter, and would drag a static
 * analyser back into its vendor dir.
 */
arch('the adapter never imports the inference engine')
    ->expect('Docuccino\Laravel')
    ->not->toUse('Docuccino\Inference\PhpStan');

it('imports no analysis toolchain at all', function (): void {
    expect(importsMatching('laravel', '/^(PHPStan|Larastan)\\\\/'))->toBe([]);
});
