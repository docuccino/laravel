<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Permission;

/**
 * Entry point for the `spatie/laravel-permission` integration (design §Phase 4). The service provider
 * spreads {@see extensions()} into the default set only when the package is installed (`class_exists`
 * guard), so docuccino/laravel never hard-requires it.
 */
final class PermissionIntegration
{
    public const PROVIDER = 'Spatie\\Permission\\PermissionServiceProvider';

    /**
     * The class-presence probe is injectable so the gated-off branch is testable where the package
     * is in fact present.
     *
     * @param  (callable(string): bool)|null  $probe
     */
    public static function installed(?callable $probe = null): bool
    {
        $probe ??= static fn (string $class): bool => class_exists($class);

        return $probe(self::PROVIDER);
    }

    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            PermissionExtension::class,
        ];
    }
}
