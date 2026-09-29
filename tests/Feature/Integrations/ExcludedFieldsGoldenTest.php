<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\ExcludedFields\PaymentController;
use Docuccino\Laravel\Tests\Fixtures\ExcludedFields\StorePaymentRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * A body tagged by one field, each branch's fields switched off by an exclude rule keyed on the tag —
 * the way a FormRequest writes a tagged union. Laravel stops running a field's rules at the first exclude
 * rule that fires, so a `required` after one holds only on the requests that keep the field. The root's
 * fields are gated on a field of `payment`, so the root stays one object with notes; `payment`'s own members
 * are gated on one of its own, so it is a tagged union (the `tagged-rules` golden is the fuller case).
 *
 * `rules()` is walked as written, so the descriptor and `new` forms fold here as the analyser folds them.
 */
$engine = static fn (): TypeEngine => WorkbenchEngine::make(traceOverrides: [
    StorePaymentRequest::class.'::rules' => TraceScript::forMethod(
        (string) (new ReflectionClass(StorePaymentRequest::class))->getFileName(),
        StorePaymentRequest::class,
        'rules',
    ),
]);

$routes = static function (Router $router): void {
    $router->post('api/zz-payments', [PaymentController::class, 'store']);
};

it('publishes a field required after an exclude rule as required only while it is kept', function () use ($engine, $routes): void {
    $result = localityBuild($routes, $engine);

    assertGolden('excluded-fields.uir.json', (new UirEmitter)->emit($result->document));

    $schemas = emittedArray($result)['components']['schemas'];
    $schema = $schemas['StorePaymentRequest'];

    // `payment`'s members are all switched on or off by `payment.method`, so each value is a branch of its
    // own: the field it keeps, required by it, and nothing else.
    expect($schema['properties']['payment']['discriminator']['propertyName'])->toBe('method')
        ->and($schemas['StorePaymentRequestPaymentCard']['required'])->toBe(['method', 'card_number'])
        ->and($schemas['StorePaymentRequestPaymentTransfer']['required'])->toBe(['method', 'iban'])
        // The root's members are gated on a field that is not one of them, so the root stays one object,
        // each conditional field required only in words.
        ->and($schema['properties']['note']['description'])->toBe('Required unless payment.method is card.')
        // A `required` or `present` written BEFORE the exclude rule runs on every request.
        ->and($schema['required'])->toBe(['payment', 'reference', 'channel']);
});

it('accepts every body the server accepts, and refuses the ones a rule ahead of an exclude refuses', function (array $body, bool $accepted) use ($engine, $routes): void {
    // The contract this is for: a client that builds a body from the published schema must be able to
    // build every body the FormRequest lets through. So the rules decide, run by Laravel itself.
    expect(excludedFieldsVerdicts($body, $routes, $engine))->toBe([$accepted, $accepted]);
})->with([
    'the card branch' => [['payment' => ['method' => 'card', 'card_number' => '4242'], 'reference' => 'r-1', 'gift_wrap' => true, 'channel' => 'web'], true],
    'the transfer branch' => [['payment' => ['method' => 'transfer', 'iban' => 'GB00'], 'reference' => 'r-2', 'note' => 'thanks', 'gift_wrap' => false, 'channel' => 'web'], true],
    'no reference, required ahead of its exclude rule' => [['payment' => ['method' => 'transfer', 'iban' => 'GB00'], 'note' => 'thanks', 'gift_wrap' => false, 'channel' => 'web'], false],
    'no channel, present ahead of its exclude rule' => [['payment' => ['method' => 'card', 'card_number' => '4242'], 'reference' => 'r-1', 'gift_wrap' => true], false],
]);

it('accepts any value in a field the tag excludes, as the server ignores it unread', function () use ($engine, $routes): void {
    // In the card branch the server excludes `iban` before its `string` runs, so it accepts and discards
    // any value there. A merged object could hold only one shape for `iban` and had to refuse this body;
    // the card branch does not carry `iban` at all, so it says what the server does.
    $body = ['payment' => ['method' => 'card', 'card_number' => '4242', 'iban' => 12345], 'reference' => 'r-1', 'gift_wrap' => true, 'channel' => 'web'];

    expect(excludedFieldsVerdicts($body, $routes, $engine))->toBe([true, true]);
});
