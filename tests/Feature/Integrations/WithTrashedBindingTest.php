<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * Real-path coverage for the soft-delete half of a binding's published facts. `->withTrashed()` is a
 * claim about what the server ACCEPTS — a value whose record is soft-deleted still resolves — and
 * nothing else in the operation states it, so it goes out as a sentence and as a fact. The route
 * without it carries neither, which is what makes the flag mean anything.
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
        ->and($parameter['x-docuccino']['facts']['routeBinding'])->toBe(['key' => 'id', 'withTrashed' => true]);
});

it('leaves a normal bound parameter without the trashed claim', function (): void {
    bindStubEngine();
    $operation = generateDocument()->document->toArray()['paths']['/api/forms/{form}']['get'] ?? [];

    $parameter = pathParameter($operation, 'form');
    expect($parameter)->not->toBeNull();
    // The binding still says what it is matched on — what it must NOT say is that a deleted record
    // resolves, because on this route one does not.
    expect($parameter['x-docuccino']['facts']['routeBinding'])->toBe(['key' => 'id'])
        ->and($parameter['description'])->not->toContain('soft-deleted (trashed)');
});
