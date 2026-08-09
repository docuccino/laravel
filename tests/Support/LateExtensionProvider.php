<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Laravel\Facades\Docuccino;
use Illuminate\Support\ServiceProvider;

/**
 * A second package provider that registers a Docuccino extension from its own boot() — used to
 * prove that a provider booting AFTER Docuccino's still contributes, because the ExtensionRegistry
 * resolves lazily at build time, never at boot (design §6 late-bound registration).
 */
final class LateExtensionProvider extends ServiceProvider
{
    public function boot(): void
    {
        Docuccino::extend(new LateBoundMarker);
    }
}
