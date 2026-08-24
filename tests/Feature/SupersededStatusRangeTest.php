<?php

declare(strict_types=1);

use Docuccino\Attributes\Response;
use Docuccino\Attributes\ResponseHeader;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Extensions\AttributeResponsesExtension;
use Docuccino\Laravel\Support\BinaryRepresentation;

/**
 * Where the layers meet. Inference publishes two kinds of stand-in for something the code never said —
 * the `3XX` status range for a redirect whose code is unstated, and the any-media-type body for a stream
 * whose Content-Type is unstated — and the attribute layer is what states them. It holds both facts, so
 * it is where the superseded placeholder is retracted.
 */
function afterResponseAttributes(array $attributes, ?callable $seed = null): OperationDraft
{
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/things'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet($attributes),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(typeToSchema: DefaultTypeMappers::all()),
    );

    $operation = new OperationDraft;
    ($seed ?? seedInferredRedirect(...))($operation);

    (new AttributeResponsesExtension)->handle($operation, $context);

    return $operation;
}

/** Exactly what InferredResponsesExtension leaves behind for a bare RedirectResponse return. */
function seedInferredRedirect(OperationDraft $operation): void
{
    $range = $operation->response('3XX');
    $range->setDescription('Redirect', Contribution::fallback());
    $range->set('headers', [
        'Location' => [
            'description' => 'The URL to follow.',
            'schema' => ['type' => 'string', 'format' => 'uri-reference'],
        ],
    ], Contribution::inference());
}

/** And for a streamed response whose building call named no Content-Type. */
function seedInferredAnyMediaType(OperationDraft $operation): void
{
    $response = $operation->response('200');
    $response->setDescription('OK', Contribution::fallback());
    foreach (BinaryRepresentation::SCHEMA as $keyword => $value) {
        $response->content(BinaryRepresentation::ANY_MEDIA_TYPE)->set($keyword, $value, Contribution::inference());
    }
}

it('retires the inferred redirect range once an attribute names the code', function (): void {
    // Publishing both would say "always 302" and "any 3xx may happen" side by side, and a generated
    // client would see two success responses.
    expect(afterResponseAttributes([new Response(status: 302, description: 'Follow the Location header.')])->responseStatuses())
        ->toBe(['302']);
});

it('hands the retired range its Location header rather than dropping it', function (): void {
    // The range stood in for the unknown CODE. The header is a fact about the response, which the
    // response class proves whichever code it answers with, so retracting the stand-in must not lose it.
    $frozen = afterResponseAttributes([new Response(status: 302)])->freeze()->responses['302'];

    expect($frozen->headers)->toBe([
        'Location' => [
            'description' => 'The URL to follow.',
            'schema' => ['type' => 'string', 'format' => 'uri-reference'],
        ],
    ]);
});

it('retires it for any code in the range, not just the redirect default', function (int $status): void {
    $operation = afterResponseAttributes([new Response(status: $status)]);

    expect($operation->responseStatuses())->toBe([(string) $status])
        ->and($operation->freeze()->responses[(string) $status]->headers)->toHaveKey('Location');
})->with([
    'moved permanently' => [301],
    'found' => [302],
    'see other' => [303],
    'temporary redirect' => [307],
    'permanent redirect' => [308],
]);

it('retires it for a header declared at a concrete code, and carries both headers', function (): void {
    // Naming a header AT 302 is a statement that 302 is what the endpoint answers with. `headers` is one
    // guarded field written whole, so the declared name has to arrive beside the inherited one.
    $operation = afterResponseAttributes([new ResponseHeader(name: 'X-Request-Id', status: 302)]);

    expect($operation->responseStatuses())->toBe(['302'])
        ->and(array_keys($operation->freeze()->responses['302']->headers ?? []))
        ->toBe(['Location', 'X-Request-Id']);
});

it('keeps the range beside a declared status of another class', function (int $status): void {
    // Declaring the success body, or an error, says nothing about which redirect the endpoint answers
    // with — so the honest range stays, Location and all. Byte-sorted, so which comes first is a fact
    // about the digits.
    $operation = afterResponseAttributes([new Response(status: $status)]);

    expect($operation->responseStatuses())
        ->toHaveCount(2)
        ->toContain('3XX', (string) $status)
        ->and($operation->freeze()->responses['3XX']->headers)->toHaveKey('Location');
})->with(['success' => [200], 'not found' => [404], 'unavailable' => [503]]);

it('leaves an error range standing beside a declared member of its own class', function (string $range, int $status): void {
    // An error range IS a member set — "any 4xx answers like this" — so declaring 404 denies nothing
    // about 409. Only the redirect range stands for one unknown code, so only it is retracted.
    $operation = afterResponseAttributes([new Response(status: $status)], function (OperationDraft $draft) use ($range): void {
        $draft->response($range)->setDescription('Problem', Contribution::integration('problem-details'));
    });

    expect($operation->responseStatuses())->toBe([(string) $status, $range]);
})->with([
    'client error' => ['4XX', 404],
    'server error' => ['5XX', 503],
]);

it('keeps every declared code when an endpoint really answers with several', function (): void {
    // The range's job was to stand in for one unknown code. An endpoint that answers 301 sometimes and
    // 302 others declares both, and the document says exactly that rather than "any 3xx".
    expect(afterResponseAttributes([new Response(status: 301), new Response(status: 302)])->responseStatuses())
        ->toBe(['301', '302']);
});

it('leaves a redirect nothing declared exactly as inference documented it', function (): void {
    expect(afterResponseAttributes([])->responseStatuses())->toBe(['3XX']);
});

it('retires the any-media-type body once an attribute names the media type', function (): void {
    // A body under the any-media-type range says the endpoint may answer with anything at all, which
    // subsumes — and so erases — the text/csv the author just declared.
    $operation = afterResponseAttributes(
        [new Response(status: 200, type: 'string', mediaType: 'text/csv')],
        seedInferredAnyMediaType(...),
    );

    expect(array_keys($operation->freeze()->responses['200']->content ?? []))->toBe(['text/csv']);
});

it('leaves an unnamed media type as the range inference published', function (object $attribute): void {
    // Two ways of naming nothing. #[Response] with no `type:` writes no body at all; one with a body and
    // no `mediaType:` publishes under the JSON default, which nobody wrote — a stream the analyzer could
    // only document as any-media-type is not proven to be JSON by a parameter default.
    $content = afterResponseAttributes([$attribute], seedInferredAnyMediaType(...))
        ->freeze()->responses['200']->content ?? [];

    expect(array_keys($content))->toContain(BinaryRepresentation::ANY_MEDIA_TYPE);
})->with([
    'no body named' => [new Response(status: 200)],
    'a body under the default media type' => [new Response(status: 200, type: 'string')],
]);
