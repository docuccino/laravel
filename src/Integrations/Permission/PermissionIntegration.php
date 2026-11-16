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

    /** @var class-string */
    private const UNAUTHORIZED = 'Spatie\\Permission\\Exceptions\\UnauthorizedException';

    /**
     * The package's middleware, every one of which reads the user itself. The class names and what they
     * throw are the same in every major from 6.
     *
     * @var list<class-string>
     */
    private const MIDDLEWARE = [
        'Spatie\\Permission\\Middleware\\PermissionMiddleware',
        'Spatie\\Permission\\Middleware\\RoleMiddleware',
        'Spatie\\Permission\\Middleware\\RoleOrPermissionMiddleware',
    ];

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
     * Each of {@see MIDDLEWARE}: a guest and a user lacking every role or permission the entry names both
     * get `UnauthorizedException`, an `HttpException` carrying 403, so the route answers no 401 of its own.
     *
     * @return array<class-string, array{refusal: null, denial: class-string, signal: string}>
     */
    public static function authenticators(): array
    {
        $authenticators = [];
        foreach (self::MIDDLEWARE as $class) {
            $authenticators[$class] = ['refusal' => null, 'denial' => self::UNAUTHORIZED, 'signal' => 'permission-middleware'];
        }

        return $authenticators;
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
