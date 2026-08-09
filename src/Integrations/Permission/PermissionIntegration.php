<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Permission;

/**
 * Entry point for the `spatie/laravel-permission` integration. The provider spreads {@see extensions()} in
 * only when the package is installed, so docuccino/laravel never hard-requires it.
 *
 * Unlike every other integration, this one is opt-in even when installed: documenting role and permission
 * names publishes the app's internal authorization taxonomy, which has to be a deliberate choice.
 */
final class PermissionIntegration
{
    public const PROVIDER = 'Spatie\\Permission\\PermissionServiceProvider';

    /**
     * The probe is injectable so the gated-off branch stays testable where the package is present.
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
