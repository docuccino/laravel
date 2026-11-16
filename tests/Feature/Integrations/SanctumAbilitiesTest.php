<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\AuthAttributesController;
use Workbench\App\Http\Controllers\FormController;

/**
 * Real-path coverage for Sanctum token abilities: the extension reads the actual gathered route
 * middleware (and the #[Abilities] attribute reflected off the action) through the pipeline, emitting
 * the x-abilities member and a requirement line beneath whatever description the operation has.
 * Sanctum is a dev dependency, so the integration is registered.
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

/**
 * The reader of the document sees the description and nothing else: `x-abilities` is for a generator.
 * The ability is enforced whatever the docblock says, so the docblock's prose must not erase it, and the
 * line must not displace the prose the author wrote either.
 */
it('states the ability beneath a docblock description rather than losing it to the docblock', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/ability-documented', [FormController::class, 'index'])->middleware(['auth:sanctum', 'abilities:forms:read']);

    bindStubEngine();
    $operation = generateDocument()->document->toArray()['paths']['/api/ability-documented']['get'] ?? [];

    expect($operation['x-abilities'])->toBe([['match' => 'all', 'abilities' => ['forms:read']]])
        ->and($operation['description'])->toBe("Returns the collection of forms visible to the caller.\n\nRequires token ability: forms:read");
});

it('states a token ability and a permission on one route, each on its own line', function (): void {
    // Both producers write at the integration layer, where the second was shadowed by the first and its
    // requirement went missing from the prose.
    setBuild('documents.default.integrations.permission.enabled', true);
    $operation = abilitiesClosureOperation('api/ability-and-permission', ['auth:sanctum', 'abilities:forms:read', 'permission:moderate forms']);

    expect($operation['description'])
        ->toContain('Requires token ability: forms:read')
        ->toContain('Requires permission: moderate forms');
});
