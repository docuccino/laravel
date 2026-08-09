<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\IntegrationsController;

/**
 * A union action returning a spatie Data envelope on one path and `response()->noContent()` on
 * another must document BOTH statuses — the Data success status (with its `{data: …}` body) AND the
 * 204 — not collapse to one. Previously no test merged a Data-envelope success with an inferred 204.
 * The route is registered ad-hoc (not in the default route set) so no committed golden churns.
 */
it('merges a spatie Data envelope status with an inferred 204 from a union action', function (): void {
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->post('api/store-or-cancel', [IntegrationsController::class, 'storeOrCancel']);

    $op = generateDocument()->document->toArray()['paths']['/api/store-or-cancel']['post'];
    $responses = $op['responses'];

    // The 204 (noContent) is documented with no body.
    expect($responses)->toHaveKey('204')
        ->and($responses['204']['content'] ?? null)->toBeNull();

    // A success status for the Data envelope is documented alongside it, carrying a JSON body.
    $successStatuses = array_values(array_filter(
        array_keys($responses),
        static fn (string $status): bool => str_starts_with($status, '2') && $status !== '204',
    ));
    expect($successStatuses)->not->toBeEmpty();

    $success = $responses[$successStatuses[0]];
    expect($success['content']['application/json']['schema'] ?? null)->not->toBeNull();
});
