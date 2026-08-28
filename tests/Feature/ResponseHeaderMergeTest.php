<?php

declare(strict_types=1);

use Docuccino\Laravel\Tests\Fixtures\ResponseHeaderMerge\ThrottledReceiptController;
use Docuccino\Laravel\Tests\Fixtures\ResponseHeaderMerge\TwiceDeclaredController;
use Docuccino\Laravel\Tests\Fixtures\ResponseHeaderMerge\UntypedHeaderController;
use Illuminate\Routing\Router;

/**
 * A `#[ResponseHeader]` contributes what it STATES. `headers` is one guarded field every producer writes
 * whole, so a declaration that rebuilt its entry from the attribute alone silently replaced members
 * nobody had mentioned — and the one collision it is reachable from is the rate-limit `429`, whose four
 * headers are documented as `required` integers that a contract check holds a response to.
 *
 * Read through the emitter, so what is asserted is the bytes a consumer gets: member order is the
 * canonicalizer's, not the order the extension happened to build the entry in.
 */
it('keeps every member of an inherited header a declaration says nothing about', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/receipts', [ThrottledReceiptController::class, 'prose'])->middleware('throttle:60,1');

    bindStubEngine();
    $document = emittedArray(generateDocument());
    $headers = resolveResponse($document, $document['paths']['/api/receipts']['get']['responses']['429'])['headers'];

    // The author wrote one sentence. They did not say the header is a string, and they did not say the
    // server has stopped always sending it — so the integration's `integer` and `required: true` stand,
    // and the three headers the declaration never named stand beside it.
    expect($headers['Retry-After'])->toBe([
        'description' => 'Wait this long before asking for the receipt again.',
        'required' => true,
        'schema' => ['type' => 'integer'],
    ])
        ->and(array_keys($headers))->toBe([
            'Retry-After',
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset',
        ])
        ->and($headers['X-RateLimit-Limit'])->toBe([
            'description' => 'The maximum number of requests permitted in the current window.',
            'required' => true,
            'schema' => ['type' => 'integer'],
        ]);
});

/**
 * @param  array<string, array<string, mixed>>  $expected
 */
it('merges a declaration onto an inherited header member by member', function (string $action, array $expected): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/receipts', [ThrottledReceiptController::class, $action])->middleware('throttle:60,1');

    bindStubEngine();
    $document = emittedArray(generateDocument());
    $headers = resolveResponse($document, $document['paths']['/api/receipts']['get']['responses']['429'])['headers'];

    foreach ($expected as $name => $entry) {
        expect($headers[$name] ?? null)->toBe($entry);
    }
})->with([
    // A stated type wins the schema and nothing else: the layer-40 statement replaces the layer-20
    // recovery for the one member it makes, and the prose and the promise it never mentioned survive.
    'a stated type wins the schema alone' => ['type', [
        'Retry-After' => [
            'description' => 'Seconds to wait before making another request.',
            'required' => true,
            'schema' => ['type' => 'string'],
        ],
    ]],
    // A written `false` is a statement, not silence — the author is describing a deployment that does
    // not always send the header, and a knob that reads the author's word and refuses it is no knob.
    'a written required: false wins the promise alone' => ['optional', [
        'Retry-After' => [
            'description' => 'Seconds to wait before making another request.',
            'required' => false,
            'schema' => ['type' => 'integer'],
        ],
    ]],
    // A different name at a status that already has headers is an addition, never a replacement — the
    // same rule that puts a declared header beside a redirect's inherited `Location`.
    'a new name joins the inherited ones' => ['fresh', [
        'Retry-After' => [
            'description' => 'Seconds to wait before making another request.',
            'required' => true,
            'schema' => ['type' => 'integer'],
        ],
        'X-Receipt-Id' => [
            'description' => 'The receipt this attempt was for.',
            'schema' => ['type' => 'string'],
        ],
    ]],
]);

it('floors a header nothing typed to a string rather than publishing no schema at all', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/receipts', [UntypedHeaderController::class, 'show']);

    bindStubEngine();
    $headers = emittedArray(generateDocument())['paths']['/api/receipts']['get']['responses']['200']['headers'];

    // Nothing inherited this name and the declaration did not type it, so the floor is all there is. A
    // Header Object carrying neither `schema` nor `content` is not one OAS can read, and a header value
    // on the wire is a string — so the floor is honest rather than a guess. It is only ever reached by
    // an entry no declaration and no producer described.
    expect($headers['X-Receipt-Id'])->toBe([
        'description' => 'Identifies this receipt.',
        'schema' => ['type' => 'string'],
    ]);
});

it('settles two declarations of one name member by member, nearest first', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/receipts', [TwiceDeclaredController::class, 'show']);

    bindStubEngine();
    $headers = emittedArray(generateDocument())['paths']['/api/receipts']['get']['responses']['200']['headers'];

    // Same layer, so precedence cannot separate them: the action's declaration takes the member it
    // states, and the controller's keeps the two it alone stated. The floor is never reached — the
    // controller typed it.
    expect($headers['X-Receipt-Id'])->toBe([
        'description' => 'Identifies the reprinted receipt.',
        'required' => true,
        'schema' => ['type' => 'integer'],
    ]);
});
