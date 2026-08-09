<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\AuthAttributesController;

/**
 * Real-path coverage for Sanctum token abilities (auth audit #5): the extension reads the actual
 * gathered route middleware (and the #[Abilities] attribute reflected off the action) through the
 * pipeline, emitting the x-abilities member and (on operations with no higher-layer description) the
 * generated description line. Sanctum is a dev dependency, so the integration is registered. Closure
 * routes (no docblock) exercise the description-line half; a documented controller proves the docblock
 * still wins while x-abilities remains the authoritative signal — the integration-layer precedence,
 * mirroring the permission integration.
 */
function abilitiesClosureOperation(string $path, array $middleware): array
{
    /** @var Router $router */
    $router = app('router');
    $router->get($path, static fn () => response()->json(['ok' => true]))->middleware($middleware);

    bindStubEngine();

    return generateDocument()->document->toArray()['paths']['/'.$path]['get'] ?? [];
}

it('documents abilities: (all-of) and ability: (any-of) middleware as x-abilities', function (): void {
    $operation = abilitiesClosureOperation('api/ability-reports', ['auth:sanctum', 'abilities:read,write', 'ability:publish']);

    expect($operation['x-abilities'])->toBe([
        ['match' => 'all', 'abilities' => ['read', 'write']],
        ['match' => 'any', 'abilities' => ['publish']],
    ])
        ->and($operation['description'])->toContain('Requires token abilities: read, write')
        ->and($operation['description'])->toContain('Requires token ability: publish')
        // The route is also Sanctum-token secured (ability middleware implies token mode).
        ->and($operation['security'])->toBe([['sanctumToken' => []]]);
});

it('documents legacy Sanctum scope middleware (FQCN) as an all-of x-abilities', function (): void {
    $operation = abilitiesClosureOperation('api/legacy-scopes', ['auth:sanctum', 'Laravel\\Sanctum\\Http\\Middleware\\CheckScopes:read,write']);

    expect($operation['x-abilities'])->toBe([
        ['match' => 'all', 'abilities' => ['read', 'write']],
    ]);
});

it('documents a #[Abilities] attribute as an all-of x-abilities with a description line', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/publish', [AuthAttributesController::class, 'publish']);

    bindStubEngine();
    $operation = generateDocument()->document->toArray()['paths']['/api/publish']['get'] ?? [];

    expect($operation['x-abilities'])->toBe([
        ['match' => 'all', 'abilities' => ['posts:publish']],
    ])
        ->and($operation['description'])->toContain('Requires token ability: posts:publish');
});

it('emits no x-abilities for a route with no ability middleware or attribute', function (): void {
    $operation = abilitiesClosureOperation('api/plain-sanctum', ['auth:sanctum']);

    expect($operation)->not->toHaveKey('x-abilities');
});
