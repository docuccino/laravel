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
 * rule that fires, so a `required` after one holds only on the requests that keep the field. Nothing else
 * in the golden corpus writes an exclude rule, so nothing could have moved when the order started to count.
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

    $schema = emittedArray($result)['components']['schemas']['StorePaymentRequest'];

    // Each branch's field is required by the tag value that keeps it, and by nothing else.
    expect($schema['properties']['payment']['required'])->toBe(['method'])
        ->and($schema['properties']['payment']['properties']['card_number']['description'])->toBe('Required when payment.method is card.')
        ->and($schema['properties']['payment']['properties']['iban']['description'])->toBe('Required when payment.method is transfer.')
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

it('keeps the type of an excluded field, refusing a value the server would ignore', function () use ($engine, $routes): void {
    // The one body the schema is stricter about, on purpose. In the card branch the server excludes
    // `iban` before its `string` runs, so it accepts and discards any value there. The schema can hold
    // only one shape per property, and the shape a consumer needs is the one the server READS on the
    // requests that keep the field; widening it to anything would cost every client the type of a value
    // it has to send, to admit a value that has no effect. The discriminated union per tag value is the
    // form that would say both.
    $body = ['payment' => ['method' => 'card', 'card_number' => '4242', 'iban' => 12345], 'reference' => 'r-1', 'gift_wrap' => true, 'channel' => 'web'];

    expect(excludedFieldsVerdicts($body, $routes, $engine))->toBe([true, false]);
});
