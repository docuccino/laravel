<?php

declare(strict_types=1);

use Docuccino\Laravel\Routing\LaravelRouteResolver;
use Illuminate\Routing\Router;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Workbench\App\Http\Controllers\AuthAttributesController;

/**
 * `x-permissions` and `x-abilities` are lists of the requirements a route enforces, and a requirement
 * stated twice is still one requirement — a client generator reading the member as a permission
 * catalogue mints the entry twice, and the generated description says the same sentence twice. Two
 * producers answer the same question, so every case is put to BOTH: a rule one of them learns and the
 * other does not is the defect back on one side.
 *
 * The route's middleware list is already unique by STRING ({@see LaravelRouteResolver}),
 * so what reaches a producer twice is one middleware written two ways — spatie's alias on a group and
 * the FQCN its `::using()` helper renders on the route — or a value repeated inside one middleware's
 * own argument list.
 */
it('publishes one permission entry however many spellings state it', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->group(['middleware' => ['permission:edit articles']], function () use ($router): void {
        $router->get('api/dedupe/perm-spellings', static fn () => response()->json(['ok' => true]))
            ->middleware(PermissionMiddleware::class.':edit articles');
    });

    bindStubEngine();
    $operation = generateDocument()->document->toArray()['paths']['/api/dedupe/perm-spellings']['get'] ?? [];

    expect($operation['x-permissions'])->toBe([['type' => 'permission', 'values' => ['edit articles']]])
        ->and($operation['description'])->toBe('Requires permission: edit articles');
});

it('publishes one ability entry however many spellings state it', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->group(['middleware' => ['abilities:orders:read']], function () use ($router): void {
        $router->get('api/dedupe/ability-spellings', static fn () => response()->json(['ok' => true]))
            ->middleware(CheckAbilities::class.':orders:read');
    });

    bindStubEngine();
    $operation = generateDocument()->document->toArray()['paths']['/api/dedupe/ability-spellings']['get'] ?? [];

    expect($operation['x-abilities'])->toBe([['match' => 'all', 'abilities' => ['orders:read']]])
        ->and($operation['description'])->toBe('Requires token ability: orders:read');
});

/**
 * The attribute and the middleware are two ways to say one thing, and an application that belts and
 * braces says it both ways.
 */
it('publishes one ability entry when the attribute restates the middleware', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->post('api/dedupe/ability-attribute', [AuthAttributesController::class, 'publish'])
        ->middleware('abilities:posts:publish');

    bindStubEngine();
    $operation = generateDocument()->document->toArray()['paths']['/api/dedupe/ability-attribute']['post'] ?? [];

    expect($operation['x-abilities'])->toBe([['match' => 'all', 'abilities' => ['posts:publish']]])
        ->and($operation['description'])->toContain('Requires token ability: posts:publish')
        ->and(substr_count((string) $operation['description'], 'Requires token ability'))->toBe(1);
});

/**
 * A pipe list is any-of and a comma list is all-of, and neither gains anything from naming one member
 * twice. The label has to follow: "any of these permissions: edit, edit" offers the reader a choice
 * between one thing and itself.
 */
it('publishes a value set, not a value list, for each producer', function (string $middleware, string $member, array $expected, string $description): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/dedupe/values', static fn () => response()->json(['ok' => true]))->middleware($middleware);

    bindStubEngine();
    $operation = generateDocument()->document->toArray()['paths']['/api/dedupe/values']['get'] ?? [];

    expect($operation[$member])->toBe($expected)
        ->and($operation['description'])->toBe($description);
})->with([
    'permission, repeated' => ['permission:edit|edit', 'x-permissions', [['type' => 'permission', 'values' => ['edit']]], 'Requires permission: edit'],
    'role, repeated among others' => ['role:admin|admin|editor', 'x-permissions', [['type' => 'role', 'values' => ['admin', 'editor']]], 'Requires any of these roles: admin, editor'],
    'role_or_permission, repeated' => ['role_or_permission:editor|editor', 'x-permissions', [['type' => 'role_or_permission', 'values' => ['editor']]], 'Requires role or permission: editor'],
    'abilities (all-of), repeated' => ['abilities:read,read', 'x-abilities', [['match' => 'all', 'abilities' => ['read']]], 'Requires token ability: read'],
    'ability (any-of), repeated among others' => ['ability:read,read,write', 'x-abilities', [['match' => 'any', 'abilities' => ['read', 'write']]], 'Requires any of these token abilities: read, write'],
]);

/**
 * Two requirements the guard tells apart are two the server enforces separately, so both are published
 * — but the guard is not in the prose, so the sentence they share is said once.
 */
it('keeps requirements a guard tells apart and still says their shared sentence once', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/dedupe/guards', static fn () => response()->json(['ok' => true]))
        ->middleware(['permission:edit,web', 'permission:edit,api']);

    bindStubEngine();
    $operation = generateDocument()->document->toArray()['paths']['/api/dedupe/guards']['get'] ?? [];

    expect($operation['x-permissions'])->toBe([
        ['type' => 'permission', 'values' => ['edit'], 'guard' => 'web'],
        ['type' => 'permission', 'values' => ['edit'], 'guard' => 'api'],
    ])->and($operation['description'])->toBe('Requires permission: edit');
});

/**
 * The published answer is a function of what the middleware SAY, never of the order they were met, so
 * every arrangement of one stack emits the same bytes. Both producers are in the one stack, because the
 * two members are contested by one list.
 */
it('does not depend on the order the middleware are written in', function (): void {
    $orders = permutationsOf([
        'permission:edit articles',
        PermissionMiddleware::class.':edit articles',
        'abilities:orders:read',
        CheckAbilities::class.':orders:read',
    ]);

    /** @var Router $router */
    $router = app('router');
    foreach ($orders as $index => $order) {
        $router->get('api/dedupe/shuffled-'.$index, static fn () => response()->json(['ok' => true]))->middleware($order);
    }

    bindStubEngine();
    $paths = generateDocument()->document->toArray()['paths'];

    $published = [];
    foreach (array_keys($orders) as $index) {
        $operation = $paths['/api/dedupe/shuffled-'.$index]['get'];
        $published[] = [
            'x-permissions' => $operation['x-permissions'] ?? null,
            'x-abilities' => $operation['x-abilities'] ?? null,
            'description' => $operation['description'] ?? null,
        ];
    }

    // The stated answer, not just agreement: 24 identically empty operations would agree too. Only one
    // requirement note reaches the description because a second write at the integration layer is
    // shadowed ({@see \Docuccino\Core\Draft\DescriptionAppender}); which one is a fact about the
    // extension order, not about the middleware order, so it belongs in this comparison unchanged.
    expect($published)->toHaveCount(24)
        ->and($published[0])->toBe([
            'x-permissions' => [['type' => 'permission', 'values' => ['edit articles']]],
            'x-abilities' => [['match' => 'all', 'abilities' => ['orders:read']]],
            'description' => 'Requires permission: edit articles',
        ]);

    foreach ($published as $index => $one) {
        expect($one)->toBe($published[0], 'permutation '.$index.' published a different answer');
    }
});
