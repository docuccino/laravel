<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\LateBoundMarker;
use Docuccino\Laravel\Tests\Support\LateExtensionProvider;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * Late-bound registration traps (design §6): nothing resolves until a build starts, so extensions
 * contributed after boot — from a second provider, between two builds, or via config — all take
 * effect, and every build re-resolves the current registry.
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
});

function titleOf(): ?string
{
    // Build through DocumentBuilder so both programmatic (Docuccino::extend) and config-declared
    // extensions are exercised on the one path every command/viewer shares.
    return app(DocumentBuilder::class)->build('default', app(TypeEngine::class))->document->info['title'] ?? null;
}

it('picks up an extension registered by a second provider booting after Docuccino', function (): void {
    // The app is already booted; registering the provider now runs its boot() immediately, which
    // is strictly "after" Docuccino's provider booted.
    app()->register(LateExtensionProvider::class);

    expect(titleOf())->toBe('LATE-BOUND');
});

it('re-resolves the registry on every build (build → extend → build)', function (): void {
    // First build: no extension registered yet.
    expect(titleOf())->not->toBe('LATE-BOUND');

    // Register between builds; the next build must reflect it.
    Docuccino::extend(new LateBoundMarker);

    expect(titleOf())->toBe('LATE-BOUND');
});

it('merges class-string extensions from config at build time', function (): void {
    config()->set('docuccino.extensions', [LateBoundMarker::class]);

    expect(titleOf())->toBe('LATE-BOUND');
});
