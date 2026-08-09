<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Permission\PermissionMiddlewareParser;

/**
 * Dataset coverage over the three middleware forms, pipe-separated any-of lists, and the optional
 * `,guard` suffix, plus the non-permission degradation (the parse map is the table).
 */
it('parses each permission middleware form', function (string $middleware, string $type, array $values, ?string $guard): void {
    $requirement = (new PermissionMiddlewareParser)->parse($middleware);

    expect($requirement)->not->toBeNull();
    expect($requirement->type)->toBe($type)
        ->and($requirement->values)->toBe($values)
        ->and($requirement->guard)->toBe($guard);
})->with([
    'single role' => ['role:admin', 'role', ['admin'], null],
    'single permission' => ['permission:edit articles', 'permission', ['edit articles'], null],
    'role_or_permission' => ['role_or_permission:editor|edit articles', 'role_or_permission', ['editor', 'edit articles'], null],
    'permission with guard' => ['permission:publish articles,web', 'permission', ['publish articles'], 'web'],
    'role any-of pipe list' => ['role:manager|writer', 'role', ['manager', 'writer'], null],
    'permission any-of with guard' => ['permission:edit|publish,api', 'permission', ['edit', 'publish'], 'api'],
    'RoleMiddleware ::using() FQCN' => ['Spatie\\Permission\\Middleware\\RoleMiddleware:admin', 'role', ['admin'], null],
    'PermissionMiddleware ::using() FQCN with guard' => ['Spatie\\Permission\\Middleware\\PermissionMiddleware:edit articles,web', 'permission', ['edit articles'], 'web'],
    'RoleOrPermissionMiddleware ::using() FQCN any-of' => ['Spatie\\Permission\\Middleware\\RoleOrPermissionMiddleware:editor|edit', 'role_or_permission', ['editor', 'edit'], null],
]);

it('returns null for a non-permission middleware', function (string $middleware): void {
    expect((new PermissionMiddlewareParser)->parse($middleware))->toBeNull();
})->with([
    'auth' => ['auth:sanctum'],
    'throttle' => ['throttle:60,1'],
    'permission-like prefix' => ['permissions:edit'],
    'empty values' => ['permission:'],
]);

it('describes each requirement type, marking multi-value pipe lists as any-of', function (): void {
    $parser = new PermissionMiddlewareParser;

    expect($parser->parse('permission:edit articles')->describe())->toBe('Requires permission: edit articles')
        ->and($parser->parse('role:admin|owner')->describe())->toBe('Requires any of these roles: admin, owner')
        ->and($parser->parse('permission:edit|publish')->describe())->toBe('Requires any of these permissions: edit, publish')
        ->and($parser->parse('role_or_permission:editor')->describe())->toBe('Requires role or permission: editor')
        ->and($parser->parse('role_or_permission:editor|admin')->describe())->toBe('Requires any of these roles or permissions: editor, admin');
});
