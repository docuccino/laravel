<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Examples\ExampleRecording;
use Docuccino\Core\Examples\RecordedExample;
use Docuccino\Core\Examples\RecordingStore;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Extensions\RecordedExamplesExtension;

/**
 * Where a recorded example sits in the precedence ladder, and what it declines to do.
 *
 * These build the draft and the context by hand because the question is precedence rather than
 * recovery; `RecordedExamplesTest` asks the same question of a real build with real attributes on it.
 */
const RECORDED_OPERATION = 'op:v1:abcdefgh12345678';

function recordedExamplesBase(): string
{
    $base = sys_get_temp_dir().'/docuccino-recorded-ext-'.getmypid().'-'.bin2hex(random_bytes(6));
    mkdir($base.'/docs/recordings', 0777, true);

    return $base;
}

/**
 * @param  array<string, mixed>  $representation
 */
function recordedContext(string $base, ?string $operationId = RECORDED_OPERATION, array $representation = []): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['GET'], '/api/invoices'),
        actionRef: new ActionRef('app/Http/Controllers/InvoiceController.php', 'App\\InvoiceController', 'index'),
        attributes: new AttributeSet,
        engine: new NullTypeEngine,
        document: new DocumentConfig(
            key: 'default',
            info: ['title' => 'T', 'version' => '1'],
            representation: $representation,
            raw: ['examples' => ['recordings' => 'docs/recordings']],
        ),
        operationId: $operationId,
    );
}

function recordedDraft(string $status = '200', string $mediaType = 'application/json'): OperationDraft
{
    $operation = new OperationDraft;
    $operation->response($status)->content($mediaType)->set('type', 'object', Contribution::inference());

    return $operation;
}

function recordInvoice(string $base, mixed $body, string $status = '200', string $mediaType = 'application/json'): void
{
    recordInvoices($base, [RecordedExample::of($status, $mediaType, $body)]);
}

/**
 * @param  list<RecordedExample>  $responses
 */
function recordInvoices(string $base, array $responses): void
{
    (new RecordingStore($base.'/docs/recordings'))->put(ExampleRecording::of(
        RECORDED_OPERATION,
        'GET /api/invoices',
        $responses,
    ));
}

/**
 * @return array<string, mixed>
 */
function recordedResponse(OperationDraft $operation, string $status = '200'): array
{
    return $operation->response($status)->freeze()->toArray();
}

it('publishes the recorded body as the media type\'s example', function (): void {
    $base = recordedExamplesBase();
    recordInvoice($base, ['id' => 1, 'total' => 10]);

    $operation = recordedDraft();
    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    expect(recordedResponse($operation)['content']['application/json']['example'])->toBe(['id' => 1, 'total' => 10]);
});

it('keeps the file in the fragment key whether or not it is there yet', function (): void {
    $base = recordedExamplesBase();
    $context = recordedContext($base);

    (new RecordedExamplesExtension($base))->handle(recordedDraft(), $context);

    expect($context->dependencies()->files())
        ->toBe([$base.'/docs/recordings/op-v1-abcdefgh12345678.json']);
});

it('steps aside for an example an author declared', function (array $named, mixed $singular, array $expected): void {
    $base = recordedExamplesBase();
    recordInvoice($base, ['id' => 1]);

    $operation = recordedDraft();
    // What `#[Example]` writes, and it writes it in Finalize — after this extension has run. The
    // declaration wins at freeze rather than by ordering, which is why the recording is attached anyway.
    $operation->response('200')->declareExamples('application/json', $named, $singular);

    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    $content = recordedResponse($operation)['content']['application/json'];

    // Exactly one of the two members, holding exactly what the author wrote: a recording neither
    // displaces an authored example nor joins it under a name the author never chose.
    expect(array_diff_key($content, ['schema' => true]))->toBe($expected);
})->with([
    'a singular one' => [[], ['id' => 'authored'], ['example' => ['id' => 'authored']]],
    'a named map' => [
        ['empty' => ['value' => ['id' => 0]]],
        null,
        ['examples' => ['empty' => ['value' => ['id' => 0]]]],
    ],
    'both, where the map wins' => [
        ['empty' => ['value' => ['id' => 0]]],
        ['id' => 'authored'],
        ['examples' => ['empty' => ['value' => ['id' => 0]]]],
    ],
]);

it('leaves the illustration another integration got there first with', function (): void {
    $base = recordedExamplesBase();
    recordInvoice($base, ['id' => 1]);

    $operation = recordedDraft();
    // The built-in error tiers attach the literals they folded, in an earlier phase.
    $operation->response('200')->setExample('application/json', ['id' => 'folded']);

    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    expect(recordedResponse($operation)['content']['application/json']['example'])->toBe(['id' => 'folded']);
});

it('publishes over an example nothing but the schema carried', function (): void {
    $base = recordedExamplesBase();
    recordInvoice($base, ['id' => 1]);

    $operation = recordedDraft();
    $operation->response('200')->content('application/json')
        ->set('example', ['id' => 'inferred'], Contribution::inference());

    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    // Different slots: an example INSIDE the schema is the schema's own, and the media type beside it
    // still has none of its own until something puts one there.
    expect(recordedResponse($operation)['content']['application/json']['example'])->toBe(['id' => 1]);
});

it('does not publish an example for something the document does not document', function (string $status, string $mediaType): void {
    $base = recordedExamplesBase();
    recordInvoice($base, ['id' => 1], $status, $mediaType);

    $operation = recordedDraft();
    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    expect(recordedResponse($operation)['content']['application/json'] ?? [])->not->toHaveKey('example');
})->with([
    'a status nothing documents' => ['418', 'application/json'],
    'a media type nothing documents' => ['200', 'application/xml'],
]);

it('refuses to publish a committed body that still holds a credential', function (): void {
    $base = recordedExamplesBase();
    recordInvoice($base, ['id' => 1, 'api_key' => 'live-secret-value']);

    $operation = recordedDraft();
    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    expect(recordedResponse($operation)['content']['application/json'])->not->toHaveKey('example');
});

it('publishes nothing at all when there is nothing to publish', function (callable $arrange): void {
    $base = recordedExamplesBase();
    $context = $arrange($base);

    $operation = recordedDraft();
    (new RecordedExamplesExtension($base))->handle($operation, $context);

    expect(recordedResponse($operation)['content']['application/json'])->not->toHaveKey('example');
})->with([
    'no recording' => [fn (string $base): RouteContext => recordedContext($base)],
    'no operation id' => [function (string $base): RouteContext {
        recordInvoice($base, ['id' => 1]);

        return recordedContext($base, null);
    }],
    'a malformed recording' => [function (string $base): RouteContext {
        file_put_contents($base.'/docs/recordings/op-v1-abcdefgh12345678.json', '{oops');

        return recordedContext($base);
    }],
    'a document that names no recordings' => [fn (string $base): RouteContext => new RouteContext(
        route: new RouteDescriptor(['GET'], '/api/invoices'),
        actionRef: new ActionRef('x.php', 'X', 'index'),
        attributes: new AttributeSet,
        engine: new NullTypeEngine,
        document: new DocumentConfig(key: 'default', info: ['title' => 'T', 'version' => '1']),
        operationId: RECORDED_OPERATION,
    )],
]);

it('publishes named recordings as the media type\'s examples map', function (): void {
    $base = recordedExamplesBase();
    recordInvoices($base, [
        RecordedExample::of('200', 'application/json', ['items' => [['sku' => 'A']]], 'full-cart'),
        RecordedExample::of('200', 'application/json', ['items' => []], 'empty-cart'),
    ]);

    $operation = recordedDraft();
    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    $content = recordedResponse($operation)['content']['application/json'];

    expect($content['examples'])->toBe([
        'empty-cart' => ['value' => ['items' => []]],
        'full-cart' => ['value' => ['items' => [['sku' => 'A']]]],
    ])->and($content)->not->toHaveKey('example');
});

it('settles a named recording against what an author wrote', function (array $named, mixed $singular, array $expected): void {
    $base = recordedExamplesBase();
    recordInvoices($base, [
        RecordedExample::of('200', 'application/json', ['id' => 'recorded'], 'empty'),
    ]);

    $operation = recordedDraft();
    $operation->response('200')->declareExamples('application/json', $named, $singular);

    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    expect(array_diff_key(recordedResponse($operation)['content']['application/json'], ['schema' => true]))->toBe($expected);
})->with([
    // A name passed at a call site is a name somebody chose, so it may sit in a map an author curated —
    // but never over one of theirs, and never beside a singular example OpenAPI would not have it beside.
    'a map with room for it' => [
        ['stocked' => ['value' => ['id' => 'authored']]],
        null,
        ['examples' => [
            'empty' => ['value' => ['id' => 'recorded']],
            'stocked' => ['value' => ['id' => 'authored']],
        ]],
    ],
    'a map that already spells the name' => [
        ['empty' => ['value' => ['id' => 'authored']]],
        null,
        ['examples' => ['empty' => ['value' => ['id' => 'authored']]]],
    ],
    'a singular one, which has nowhere for it to go' => [
        [],
        ['id' => 'authored'],
        ['example' => ['id' => 'authored']],
    ],
]);

it('publishes every recorded name on an error status, as it does on any other', function (array $representation): void {
    $base = recordedExamplesBase();
    recordInvoices($base, [
        RecordedExample::of('403', 'application/json', ['code' => 'forbidden', 'detail' => 'No.'], 'expired'),
        RecordedExample::of('403', 'application/json', ['code' => 'forbidden'], 'missing'),
    ]);

    $operation = recordedDraft('403');
    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base, representation: $representation));

    // The map survives the shared-error pass: {@see SharedErrorResponses::illustrated()} lifts it into
    // the component under the names it carries rather than leaving the response behind, so there is
    // nothing here for the hoist setting to change. Asserted under BOTH values because that is the
    // claim — an answer that varied with the setting is what this extension used to give.
    $content = recordedResponse($operation, '403')['content']['application/json'];

    expect($content['examples'])->toBe([
        'expired' => ['value' => ['code' => 'forbidden', 'detail' => 'No.']],
        'missing' => ['value' => ['code' => 'forbidden']],
    ])->and($content)->not->toHaveKey('example');
})->with([
    'sharing error components' => [[]],
    'sharing none' => [['errors' => ['components' => false]]],
]);

it('reads no status when it decides which member a name publishes into', function (string $status): void {
    // The one branch this extension may take is named-or-not. A status-dependent one is what published a
    // 404's recordings under a different rule from a 200's, so the whole range is asserted alike.
    $base = recordedExamplesBase();
    recordInvoices($base, [RecordedExample::of($status, 'application/json', ['code' => 'x'], 'named')]);

    $operation = recordedDraft($status);
    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    expect(recordedResponse($operation, $status)['content']['application/json'])
        ->toHaveKey('examples')
        ->and(recordedResponse($operation, $status)['content']['application/json'])
        ->not->toHaveKey('example');
})->with([
    'a 200' => ['200'],
    'a 399' => ['399'],
    'a 404' => ['404'],
    'a 500' => ['500'],
    'a 4XX range' => ['4XX'],
]);

it('drops only the named recording that still holds a credential', function (): void {
    $base = recordedExamplesBase();
    recordInvoices($base, [
        RecordedExample::of('200', 'application/json', ['id' => 1, 'api_key' => 'live-secret-value'], 'leaky'),
        RecordedExample::of('200', 'application/json', ['id' => 2], 'clean'),
    ]);

    $operation = recordedDraft();
    (new RecordedExamplesExtension($base))->handle($operation, recordedContext($base));

    expect(recordedResponse($operation)['content']['application/json']['examples'])
        ->toBe(['clean' => ['value' => ['id' => 2]]]);
});
