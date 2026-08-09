<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

/**
 * Entry point for the Sanctum integration (design §Phase 4). The service provider spreads
 * {@see extensions()} into the default set only when Sanctum is installed (`class_exists` guard),
 * so docuccino/laravel never hard-requires it.
 */
final class SanctumIntegration
{
    public const SANCTUM = 'Laravel\\Sanctum\\Sanctum';

    /**
     * The class-presence probe is injectable so the gated-off branch is testable where the package
     * is in fact present.
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
            // Environment-digest seam (A4): the auth guards + session cookie shape Sanctum security
            // output, so they feed the document-level fragment-cache digest.
            SanctumDigestContributor::class,
        ];
    }
}
