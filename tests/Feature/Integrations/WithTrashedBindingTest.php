<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * Real-path coverage for withTrashed route-binding flagging (design §Phase 4): a bound-model route
 * declared with ->withTrashed() flags each bound path parameter with a description note and a stable
 * x-docuccino.routeBinding.withTrashed semantic fact; a normal binding carries neither.
 */
it('flags a withTrashed bound parameter with a note and an x-docuccino fact', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/trashed-forms/{form}', [FormController::class, 'show'])->withTrashed();

    bindStubEngine();
    $operation = generateDocument()->document->toArray()['paths']['/api/trashed-forms/{form}']['get'] ?? [];

    $parameter = pathParameter($operation, 'form');
    expect($parameter)->not->toBeNull();
    expect($parameter['description'])->toContain('soft-deleted (trashed)')
        ->and($parameter['x-docuccino']['facts']['routeBinding'])->toBe(['withTrashed' => true]);
});

it('leaves a normal bound parameter unflagged', function (): void {
    bindStubEngine();
    $operation = generateDocument()->document->toArray()['paths']['/api/forms/{form}']['get'] ?? [];

    $parameter = pathParameter($operation, 'form');
    expect($parameter)->not->toBeNull();
    expect($parameter['x-docuccino']['facts'] ?? null)->toBeNull();
    expect($parameter['description'] ?? null)->not->toBe('Resolves soft-deleted (trashed) records as well as active ones.');
});
