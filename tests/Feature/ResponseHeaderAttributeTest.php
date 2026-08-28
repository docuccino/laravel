<?php

declare(strict_types=1);

use Docuccino\Laravel\Tests\Fixtures\ResponseHeaderAttribute\ReceiptController;
use Illuminate\Routing\Router;

/**
 * `#[ResponseHeader(required: true)]` is how an author says the server always sends a header — the same
 * claim the rate-limit integration makes about `Retry-After`, said by hand. It has to reach the emitted
 * document, because that is the only place a generated client or a contract check can read it.
 */
it('publishes a declared response header as required, and its neighbour as optional', function (): void {
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->get('api/receipts/{receipt}', [ReceiptController::class, 'show']);

    // Read through the emitter, so what is asserted is the bytes a consumer gets — canonical member
    // order included, which `toArray()` does not have.
    $headers = emittedArray(generateDocument())['paths']['/api/receipts/{receipt}']['get']['responses']['200']['headers'];

    expect($headers['X-Receipt-Id'])->toBe([
        'description' => 'Identifies this receipt.',
        'required' => true,
        'schema' => ['type' => 'string'],
    ])
        // Undeclared is not `required: false`: OAS already defaults it, so writing the member out would
        // add noise to every header anyone has ever documented and say nothing new.
        ->and($headers['X-Reprint-Of'])->toBe([
            'description' => 'Present only on a reprint.',
            'schema' => ['type' => 'string'],
        ]);
});
