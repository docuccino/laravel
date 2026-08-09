<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

/**
 * Entry point for the Passport integration (design §Phase 4). The service provider spreads
 * {@see extensions()} into the default set only when Passport is installed (`class_exists` guard),
 * so docuccino/laravel never hard-requires it.
 */
final class PassportIntegration
{
    public const PASSPORT = 'Laravel\\Passport\\Passport';

    /**
     * The class-presence probe is injectable so the gated-off branch is testable where the package
     * is in fact present.
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
            // Environment-digest seam (A4): app.url + the scope catalogue + grants shape the oauth2
            // scheme, so they feed the document-level fragment-cache digest.
            PassportDigestContributor::class,
        ];
    }
}
