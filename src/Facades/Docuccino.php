<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Facades;

use Docuccino\Laravel\Registry\ExtensionRegistry;
use Illuminate\Support\Facades\Facade;

/**
 * The public entry point for late-bound extension registration (design §6).
 *
 * `Docuccino::extend(FooExtension::class)` or `Docuccino::extend(fn (Registrar $r) => …)` works
 * from any provider's `register()`/`boot()` in any order — the registry it proxies resolves
 * nothing until a build starts.
 *
 * @method static void extend(string|object $extension)
 *
 * @see ExtensionRegistry
 */
final class Docuccino extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ExtensionRegistry::class;
    }
}
