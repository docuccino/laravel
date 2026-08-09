<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * Real-path coverage for the spatie/laravel-permission integration (design §Phase 4): the extension
 * reads the actual gathered route middleware through the pipeline and contributes the structured
 * x-permissions member (always) plus a generated description line (on operations with no higher-layer
 * description — a docblock/attribute description still wins, per the integration-layer precedence).
 */
function permissionClosureOperation(string $path, array $middleware): array
{
    /** @var Router $router */
    $router = app('router');
    $router->get($path, static fn () => response()->json(['ok' => true]))->middleware($middleware);

    bindStubEngine();
    $document = generateDocument()->document->toArray();

    return $document['paths']['/'.$path]['get'] ?? [];
}

it('emits x-permissions and a generated description line for a permission middleware', function (): void {
    $operation = permissionClosureOperation('api/perm-articles', ['permission:edit articles,web']);

    expect($operation['x-permissions'])->toBe([
        ['type' => 'permission', 'values' => ['edit articles'], 'guard' => 'web'],
    ]);
    expect($operation['description'])->toContain('Requires permission: edit articles');
});

it('accumulates multiple requirements (role + permission) with a line each', function (): void {
    $operation = permissionClosureOperation('api/perm-mixed', ['role:admin', 'permission:publish articles']);

    expect($operation['x-permissions'])->toBe([
        ['type' => 'role', 'values' => ['admin']],
        ['type' => 'permission', 'values' => ['publish articles']],
    ]);
    expect($operation['description'])->toContain('Requires role: admin')
        ->and($operation['description'])->toContain('Requires permission: publish articles');
});

it('records a role_or_permission any-of requirement', function (): void {
    $operation = permissionClosureOperation('api/perm-any', ['role_or_permission:editor|edit articles']);

    expect($operation['x-permissions'])->toBe([
        ['type' => 'role_or_permission', 'values' => ['editor', 'edit articles']],
    ]);
});

it('keeps a docblock description but still emits the structured x-permissions', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/perm-documented', [FormController::class, 'index'])->middleware('permission:view forms');

    bindStubEngine();
    $operation = generateDocument()->document->toArray()['paths']['/api/perm-documented']['get'] ?? [];

    expect($operation['x-permissions'])->toBe([['type' => 'permission', 'values' => ['view forms']]])
        ->and($operation['description'])->toBe('Returns the collection of forms visible to the caller.');
});

it('adds nothing for a route without permission middleware', function (): void {
    $operation = permissionClosureOperation('api/perm-none', ['auth:web']);

    expect($operation)->not->toHaveKey('x-permissions');
});
