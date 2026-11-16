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

    /** @var class-string */
    private const AUTHENTICATION = 'Illuminate\\Auth\\AuthenticationException';

    /**
     * Sanctum's ability middleware, each with what it throws at a token lacking an ability the entry names.
     * The deprecated scope pair delegates to the ability pair and renames the exception.
     *
     * @var array<class-string, class-string>
     */
    private const DENIALS = [
        'Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities' => 'Laravel\\Sanctum\\Exceptions\\MissingAbilityException',
        'Laravel\\Sanctum\\Http\\Middleware\\CheckForAnyAbility' => 'Laravel\\Sanctum\\Exceptions\\MissingAbilityException',
        'Laravel\\Sanctum\\Http\\Middleware\\CheckScopes' => 'Laravel\\Sanctum\\Exceptions\\MissingScopeException',
        'Laravel\\Sanctum\\Http\\Middleware\\CheckForAnyScope' => 'Laravel\\Sanctum\\Exceptions\\MissingScopeException',
    ];

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
     * Sanctum's ability middleware, which reads the request's user itself and so refuses a request without
     * a token with `AuthenticationException`, `auth:sanctum` or not.
     *
     * @return array<class-string, array{refusal: class-string, denial: class-string, signal: string}>
     */
    public static function authenticators(): array
    {
        $authenticators = [];
        foreach (self::DENIALS as $class => $denial) {
            $authenticators[$class] = ['refusal' => self::AUTHENTICATION, 'denial' => $denial, 'signal' => 'token-middleware'];
        }

        return $authenticators;
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
