<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Permission;

/**
 * Parses a `spatie/laravel-permission` middleware string into a {@see PermissionRequirement}. The three
 * aliases — `role:`, `permission:`, `role_or_permission:` — each take a pipe-separated any-of list and an
 * optional `,guard` suffix (`permission:edit articles,web`). Each also ships a `::using()` helper that
 * renders the middleware as its class FQCN, the style spatie's docs now promote, so those prefixes match
 * too. Null for anything else. Pure, so the middleware map is dataset-testable.
 */
final class PermissionMiddlewareParser
{
    /**
     * Prefix → requirement type, longest alias first so no short alias shadows another.
     *
     * @var array<string, string>
     */
    private const PREFIXES = [
        'role_or_permission:' => 'role_or_permission',
        'permission:' => 'permission',
        'role:' => 'role',
        'Spatie\\Permission\\Middleware\\RoleOrPermissionMiddleware:' => 'role_or_permission',
        'Spatie\\Permission\\Middleware\\PermissionMiddleware:' => 'permission',
        'Spatie\\Permission\\Middleware\\RoleMiddleware:' => 'role',
    ];

    public function parse(string $middleware): ?PermissionRequirement
    {
        foreach (self::PREFIXES as $prefix => $type) {
            if (! str_starts_with($middleware, $prefix)) {
                continue;
            }

            $parameters = substr($middleware, strlen($prefix));
            $parts = explode(',', $parameters, 2);
            $values = array_values(array_filter(array_map('trim', explode('|', $parts[0])), static fn (string $v): bool => $v !== ''));
            $guard = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : null;

            return $values === [] ? null : new PermissionRequirement($type, $values, $guard);
        }

        return null;
    }
}
