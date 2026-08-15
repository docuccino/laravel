<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

/**
 * Entry point for the Sanctum integration. The provider spreads {@see extensions()} in only when Sanctum is
 * installed, so docuccino/laravel never hard-requires it.
 */
final class SanctumIntegration
{
    public const SANCTUM = 'Laravel\\Sanctum\\Sanctum';

    /**
     * The probe is injectable so the gated-off branch stays testable where the package is present.
     *
     * @param  (callable(string): bool)|null  $probe
     */
    public static function installed(?callable $probe = null): bool
    {
        $probe ??= static fn (string $class): bool => class_exists($class);

        return $probe(self::SANCTUM);
    }

    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            SanctumSecurityExtension::class,
            SanctumAbilitiesExtension::class,
            SanctumCookieReport::class,
            SanctumDigestContributor::class,
        ];
    }
}
