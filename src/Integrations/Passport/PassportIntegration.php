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

    /** @var class-string */
    private const AUTHENTICATION = 'Laravel\\Passport\\Exceptions\\AuthenticationException';

    /** @var class-string */
    private const MISSING_SCOPE = 'Laravel\\Passport\\Exceptions\\MissingScopeException';

    /**
     * Passport's middleware that checks the token itself, auth guard or not. Passport 13 renamed every one
     * ({@see ScopeMiddlewareParser}), so both generations are listed and {@see authenticators()} keeps the
     * names the installed major defines.
     */
    private const AUTHENTICATORS = [
        'Laravel\\Passport\\Http\\Middleware\\CheckScopes',
        'Laravel\\Passport\\Http\\Middleware\\CheckForAnyScope',
        'Laravel\\Passport\\Http\\Middleware\\CheckClientCredentials',
        'Laravel\\Passport\\Http\\Middleware\\CheckClientCredentialsForAnyScope',
        'Laravel\\Passport\\Http\\Middleware\\CheckToken',
        'Laravel\\Passport\\Http\\Middleware\\CheckTokenForAnyScope',
        'Laravel\\Passport\\Http\\Middleware\\EnsureClientIsResourceOwner',
    ];

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
     * Each of {@see AUTHENTICATORS} the installed Passport defines: every one refuses a request without a
     * valid token with Passport's `AuthenticationException`, and a token lacking a scope the entry names
     * with `MissingScopeException`, in both majors.
     *
     * @return array<class-string, array{refusal: class-string, denial: class-string, signal: string}>
     */
    public static function authenticators(): array
    {
        $authenticators = [];
        foreach (self::candidates() as $class) {
            if (class_exists($class)) {
                $authenticators[$class] = ['refusal' => self::AUTHENTICATION, 'denial' => self::MISSING_SCOPE, 'signal' => 'token-middleware'];
            }
        }

        return $authenticators;
    }

    /**
     * Typed as plain names: one generation's classes are never installed beside the other's.
     *
     * @return list<string>
     */
    private static function candidates(): array
    {
        return self::AUTHENTICATORS;
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
