<?php

declare(strict_types=1);

use Docuccino\Core\Patch\Layer;
use Docuccino\Laravel\Support\OverrideHint;

/**
 * The "what to do about it" half of the report. A wrong lever is worse than none — the reader tries
 * it, nothing changes, and they stop trusting the rest of the output — so every row here is a field
 * an attribute really does write, and everything else degrades to the generic truth.
 */
it('names the attribute that writes an operation-level field', function (string $field, string $lever): void {
    expect(OverrideHint::for('operation', $field, Layer::Inference))->toBe('set it with '.$lever);
})->with([
    'tags' => ['tags', "#[Group('Invoices')]"],
    'operationId' => ['operationId', "#[OperationId('storeInvoice')]"],
    'deprecated' => ['deprecated', '#[DeprecatedOperation]'],
    'summary' => ['summary', "#[Summary('Create an invoice')]"],
    'description' => ['description', "#[Description('…')]"],
    'requestBody' => ['requestBody', "#[BodyParameter(name: 'total')]"],
    'the internal marker' => ['x-internal', '#[Internal]'],
]);

it('names the attribute that owns a parameter, whichever place it is in', function (string $label, string $lever): void {
    expect(OverrideHint::for($label, 'required', Layer::Integration))->toBe('set it with '.$lever);
})->with([
    'query' => ['parameters.query:status', "#[QueryParameter(name: 'status')]"],
    'path' => ['parameters.path:invoice', "#[PathParameter(name: 'invoice')]"],
    'header' => ['parameters.header:X-Tenant', "#[HeaderParameter(name: 'X-Tenant')]"],
    'cookie' => ['parameters.cookie:session', "#[CookieParameter(name: 'session')]"],
    // The attribute writes the parameter's schema keywords too, so the node under it answers the same.
    'the schema under one' => ['parameters.query:page.schema', "#[QueryParameter(name: 'page')]"],
    'a name with brackets in it' => ['parameters.query:filter[status]', "#[QueryParameter(name: 'filter[status]')]"],
]);

it('names the attribute that writes a response', function (string $label, string $field, string $lever): void {
    expect(OverrideHint::for($label, $field, Layer::Fallback))->toBe('set it with '.$lever);
})->with([
    'a description' => ['responses.201', 'description', "#[Response(status: 201, description: '…')]"],
    'the body' => ['responses.201.content.application/json.schema', 'type', '#[Response(status: 201, type: Invoice::class)]'],
    'headers' => ['responses.204', 'headers', "#[ResponseHeader(name: 'X-Total', status: 204)]"],
    // `component` is not a member of the response at all — it is the shared error body's published
    // name, and #[Response] would not touch it.
    'a shared error name' => ['responses.404', 'component', "#[ErrorComponent('InvoiceNotFound')] on the exception or its render method"],
]);

it('falls back to the generic truth wherever no attribute writes the field', function (string $label, string $field): void {
    expect(OverrideHint::for($label, $field, Layer::Inference))->toBe('no attribute writes this — an overlay outranks inference');
})->with([
    'a member an integration invented' => ['operation', 'x-rate-limit'],
    'a parameter place we do not know' => ['parameters.matrix:coords', 'schema'],
    'a parameter with no name' => ['parameters.query:', 'required'],
    'a status that is not one' => ['responses.{status}', 'description'],
    'a node under nothing we recognise' => ['webhooks.invoice-paid', 'description'],
    'a component reached by $ref' => ['#/components/schemas/Invoice', 'properties'],
]);

/**
 * The top of the ladder has nothing above it to suggest, so it says what it is instead of inventing a
 * lever that would not work.
 */
it('says what the rung means where nothing above it is worth naming', function (Layer $winner, string $hint): void {
    expect(OverrideHint::for('operation', 'tags', $winner))->toBe($hint);
})->with([
    'attribute' => [Layer::Attribute, 'edit the attribute above, or outrank it with an overlay'],
    'overlay' => [Layer::Overlay, 'edit the overlay that set it; only config outranks an overlay'],
    'config' => [Layer::Config, 'config is the top rung — edit config/docuccino.php'],
]);

/** Every rung below the attribute layer gets the same shape of answer, naming its own rung. */
it('names the winning rung in the generic answer', function (Layer $winner): void {
    expect(OverrideHint::for('operation', 'x-rate-limit', $winner))->toBe('no attribute writes this — an overlay outranks '.$winner->label());
})->with([
    'fallback' => [Layer::Fallback],
    'inference' => [Layer::Inference],
    'integration' => [Layer::Integration],
    'docblock' => [Layer::Docblock],
]);
