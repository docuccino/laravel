<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

/**
 * Entry point for the Passport integration. The provider spreads {@see extensions()} in only when Passport
 * is installed, so docuccino/laravel never hard-requires it.
 */
final class PassportIntegration
{
    public const PASSPORT = 'Laravel\\Passport\\Passport';

    /**
     * The probe is injectable so the gated-off branch stays testable where the package is present.
     *
     * @param  (callable(string): bool)|null  $probe
     */
    public static function installed(?callable $probe = null): bool
    {
        $probe ??= static fn (string $class): bool => class_exists($class);

        return $probe(self::PASSPORT);
    }

    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            PassportSecurityExtension::class,
            PassportDigestContributor::class,
        ];
    }
}
