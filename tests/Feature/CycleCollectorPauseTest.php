<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\RouteFilters\BreakingFilter;

/*
 * Every collection the cycle collector makes during a build walks the analyser's retained graph and frees
 * next to nothing, which is why PHPStan's own command runs its analysis with the collector off. The build
 * pauses it for exactly the operations it builds, and hands the process back the collector it found.
 */
afterEach(function (): void {
    gc_enable();
});

it('pauses the cycle collector while operations build, and restores it after', function (): void {
    $engine = collectorObservingEngine();
    app()->instance(TypeEngine::class, $engine);
    gc_enable();

    generateDocument();

    // Asked at all, or the pause was never in force while anything ran.
    expect($engine->collecting)->not->toBeEmpty()
        ->and(array_unique($engine->collecting))->toBe([false])
        ->and(gc_enabled())->toBeTrue();
});

it('leaves a collector the host had turned off turned off', function (): void {
    $engine = collectorObservingEngine();
    app()->instance(TypeEngine::class, $engine);
    gc_disable();

    generateDocument();

    expect($engine->collecting)->not->toBeEmpty()
        ->and(gc_enabled())->toBeFalse();
});

it('restores the collector when the build fails while it is paused', function (): void {
    // The filter is asked about each route while the build walks them, which is inside the pause.
    setBuild('documents.default.routes.filter', BreakingFilter::class);
    gc_enable();

    expect(static fn () => generateDocument())->toThrow(RuntimeException::class, 'A filter that broke.')
        ->and(gc_enabled())->toBeTrue();
});
