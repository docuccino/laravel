<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\Postman\CollectionEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\Webhooks\Collision\AlphaClaim;
use Docuccino\Laravel\Tests\Fixtures\Webhooks\Collision\BetaClaim;
use Docuccino\Laravel\Tests\Fixtures\Webhooks\Locality\Anchor\Anchor;
use Docuccino\Laravel\Tests\Fixtures\Webhooks\Locality\Neighbour\Neighbour;
use Docuccino\Laravel\Tests\Support\ThrowingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/*
 * `#[Webhook]` end to end: discovery from a configured directory, the payload through the ordinary
 * type machinery, and the operation published under `webhooks` — where the emitters, the differ and
 * the canonicalizer have been waiting for a producer.
 */

/**
 * Point the document at a webhook directory the way the shipped config documents it — relative to the
 * application base path — by basing the app on the adapter package, so the fixtures sit inside it
 * exactly as `app/Webhooks` sits inside a real application.
 *
 * @return callable(array<string, mixed>): array<string, mixed>
 */
function withWebhooksIn(string $dir): callable
{
    app()->setBasePath(dirname(__DIR__, 2));

    return static function (array $raw) use ($dir): array {
        $raw['webhooks'] = ['dir' => $dir];

        return $raw;
    };
}

/** @return callable(array<string, mixed>): array<string, mixed> */
function withWorkbenchWebhooks(): callable
{
    return withWebhooksIn('workbench/app/Webhooks');
}

it('compiles the workbench webhook classes into the UIR byte-identical to its golden', function (): void {
    bindStubEngine();

    assertGolden('workbench-webhooks.uir.json', (new UirEmitter)->emit(generateDocument(withWorkbenchWebhooks())->document));
});

it('publishes one webhook per annotated class, and none for a class that opted out', function (): void {
    $document = stubDocumentArray(withWorkbenchWebhooks());

    // #[ExcludeFromDocs] filters a webhook class exactly as it filters a controller.
    expect(array_keys($document['webhooks']))->toBe(['form.submitted', 'widget.archived'])
        ->and($document['webhooks'])->not->toHaveKey('legacy.ping');
});

it('leaves the document alone when no webhook directory is configured', function (): void {
    expect(stubDocumentArray())->not->toHaveKey('webhooks');
});

it('reads the payload through the type machinery, as a $ref to the same component a route would get', function (): void {
    $document = stubDocumentArray(withWorkbenchWebhooks());
    $body = $document['webhooks']['widget.archived']['put']['requestBody'];

    // `payload:` named WidgetData, which the API-resource/Data machinery already documents — the
    // webhook body is a reference to that one component, not a second copy of its shape.
    expect($body['required'])->toBeTrue()
        ->and($body['content']['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/WidgetData'])
        ->and($document['components']['schemas']['WidgetData']['properties'])->toHaveKeys(['id', 'name', 'status']);
});

it('carries the class docblock, its #[Group] and its #[DeprecatedOperation] onto the operation', function (): void {
    $document = stubDocumentArray(withWorkbenchWebhooks());
    $submitted = $document['webhooks']['form.submitted']['post'];
    $archived = $document['webhooks']['widget.archived']['put'];

    expect($submitted['summary'])->toBe('A form was submitted.')
        ->and($submitted['description'])->toContain('exponential backoff')
        ->and($submitted['tags'])->toBe(['Forms'])
        ->and($submitted['operationId'])->toBe('form.submitted')
        ->and($archived['deprecated'])->toBeTrue();
});

it('documents what the receiver is expected to answer', function (): void {
    $document = stubDocumentArray(withWorkbenchWebhooks());

    // The default acknowledgement is what a receiver must return for the delivery to count…
    expect($document['webhooks']['form.submitted']['post']['responses']['200']['description'])->toBe('Delivery accepted.')
        // …and #[Response] on the class documents any other status the sender acts on.
        ->and($document['webhooks']['widget.archived']['put']['responses']['202']['description'])->toBe('Queued for processing.');
});

it('takes every #[Webhook] parameter from the attribute', function (string $name, string $method, callable $assert): void {
    $document = stubDocumentArray(withWebhooksIn('tests/Fixtures/Webhooks/Params'));

    expect($document['webhooks'])->toHaveKey($name)
        ->and(array_keys($document['webhooks'][$name]))->toBe([$method]);

    $assert($document['webhooks'][$name][$method]);
})->with([
    'name, and post as the method a receiver implements' => ['params.defaults', 'post', function (array $operation): void {
        expect($operation['requestBody']['content'])->toHaveKey('application/json');
    }],
    'method, normalised to the path-item member' => ['params.method', 'patch', function (array $operation): void {
        expect($operation['operationId'])->toBe('params.method');
    }],
    'payload, as a type string rather than only a class' => ['params.payload', 'post', function (array $operation): void {
        expect($operation['requestBody']['content']['application/json']['schema']['properties'])->toHaveKeys(['id', 'name']);
    }],
    'mediaType, for a delivery that is not plain JSON' => ['params.media-type', 'post', function (array $operation): void {
        expect(array_keys($operation['requestBody']['content']))->toBe(['application/cloudevents+json']);
    }],
]);

it('degrades what it cannot take at its word, publishes what it can, and says so', function (): void {
    bindStubEngine();

    $result = generateDocument(withWebhooksIn('tests/Fixtures/Webhooks/Degraded'));
    $document = $result->document->toArray();
    $codes = array_map(static fn (Diagnostic $d): string => $d->code, $result->diagnostics);

    expect($codes)->toContain('webhook.name-invalid', 'webhook.method-unknown', 'webhook.payload-unresolved')
        // A nameless webhook has no key to be published under, so it is omitted rather than guessed at.
        ->and(array_keys($document['webhooks']))->toBe(['degraded.odd-method', 'degraded.unresolvable', 'degraded.untouched'])
        // An unrepresentable method degrades to the one every webhook uses rather than emitting a
        // path-item member OpenAPI has no slot for.
        ->and(array_keys($document['webhooks']['degraded.odd-method']))->toBe(['post'])
        // A payload that resolves to no shape is published as an unconstrained body — vague and true.
        ->and($document['webhooks']['degraded.unresolvable']['post']['requestBody']['content']['application/json']['schema'])->toBe([])
        // …and the webhook beside them is untouched by any of it.
        ->and($document['webhooks']['degraded.untouched']['post']['operationId'])->toBe('degraded.untouched');
});

it('settles two classes claiming one webhook name on the pair, not on which was met first', function (): void {
    bindStubEngine();

    $result = generateDocument(withWebhooksIn('tests/Fixtures/Webhooks/Collision'));
    $collisions = diagnosticsCoded($result->diagnostics, 'webhook.name-collision');

    expect($collisions)->toHaveCount(1)
        ->and($collisions[0]->severity->value)->toBe('error')
        ->and($collisions[0]->message)->toContain(AlphaClaim::class)
        ->and($collisions[0]->message)->toContain(BetaClaim::class)
        // The lower FQCN keeps the name, so the answer is a function of the two contestants.
        ->and($result->document->toArray()['webhooks']['collision.claimed']['post']['operationId'])->toBe('collision.claimed');
});

it('pins a webhook to the documents #[InDocs] names', function (): void {
    bindStubEngine();

    $document = generateDocument(withWebhooksIn('tests/Fixtures/Webhooks/Pinned'))->document->toArray();

    expect(array_keys($document['webhooks']))->toBe(['pinned.everywhere']);
});

it('identifies a webhook operation the way the differ expects to find it', function (): void {
    $document = stubDocumentArray(withWorkbenchWebhooks());
    $id = $document['webhooks']['form.submitted']['post']['x-docuccino']['id'];

    expect($id)->toStartWith('op:v1:')
        // Stable across builds, and a function of the name rather than of discovery order.
        ->and(stubDocumentArray(withWorkbenchWebhooks())['webhooks']['form.submitted']['post']['x-docuccino']['id'])->toBe($id);
});

it('survives every OpenAPI version the exporter offers, and says what 3.0 loses', function (): void {
    bindStubEngine();

    $document = generateDocument(withWorkbenchWebhooks())->document;

    $oas32 = (new OpenApi32Emitter)->emitWithReport($document);
    $oas31 = (new OpenApi31DownlevelEmitter)->emitWithReport($document);
    $oas30 = (new OpenApi30DownlevelEmitter)->emitWithReport($document);

    /** @var array<string, mixed> $decoded32 */
    $decoded32 = json_decode($oas32->output, true, flags: JSON_THROW_ON_ERROR);
    /** @var array<string, mixed> $decoded31 */
    $decoded31 = json_decode($oas31->output, true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($decoded32['webhooks']))->toBe(['form.submitted', 'widget.archived'])
        ->and(array_keys($decoded31['webhooks']))->toBe(['form.submitted', 'widget.archived'])
        // 3.0 has no `webhooks` member at all, so the contract is dropped — and named, not counted.
        ->and($oas30->output)->not->toContain('form.submitted')
        ->and(array_map(static fn (Diagnostic $d): string => $d->code, $oas30->report->warnings()))->toContain('downlevel.webhooks')
        ->and(implode(' ', array_map(static fn (Diagnostic $d): string => $d->message, $oas30->report->warnings())))
        ->toContain('form.submitted, widget.archived');
});

it('reports the webhooks a Postman collection has nowhere to put', function (): void {
    bindStubEngine();

    $report = (new CollectionEmitter)->emitWithReport(generateDocument(withWorkbenchWebhooks())->document)->report;

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $report->warnings()))->toContain('postman.webhooks-dropped');
});

it('transcodes losslessly into OAS 3.2', function (): void {
    bindStubEngine();

    $document = generateDocument(withWorkbenchWebhooks())->document;

    /** @var array<string, mixed> $uir */
    $uir = json_decode((new UirEmitter)->emit($document), true, flags: JSON_THROW_ON_ERROR);
    /** @var array<string, mixed> $openapi */
    $openapi = json_decode((new OpenApi32Emitter)->emit($document), true, flags: JSON_THROW_ON_ERROR);

    expect($openapi['webhooks'])->toBe(stripDocuccino($uir)['webhooks']);
});

it('validates against the UIR schema with webhooks in it', function (): void {
    bindStubEngine();

    $result = generateDocument(withWorkbenchWebhooks());

    expect(diagnosticsCoded($result->diagnostics, 'document.schema-invalid'))->toBe([]);
});

it('diffs a produced webhook as the operation it is', function (): void {
    $build = static function (string $dir, ?callable $engine = null): array {
        app()->instance(TypeEngine::class, ($engine ?? static fn (): TypeEngine => WorkbenchEngine::make(classOverrides: [
            Anchor::class => new ClassMetadata(Anchor::class, [new PropertyMetadata('id', ScalarT::int())]),
            Neighbour::class => new ClassMetadata(Neighbour::class, [new PropertyMetadata('reference', ScalarT::string())]),
        ]))());

        return generateDocument(withWebhooksIn($dir))->document->toArray();
    };

    $alone = $build('tests/Fixtures/Webhooks/Locality/Anchor');
    $beside = $build('tests/Fixtures/Webhooks/Locality');
    $widened = $build('tests/Fixtures/Webhooks/Locality/Anchor', static fn (): TypeEngine => WorkbenchEngine::make(classOverrides: [
        Anchor::class => new ClassMetadata(Anchor::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('occurredAt', ScalarT::string()),
        ]),
    ]));

    $resummarised = $alone;
    $resummarised['webhooks']['locality.anchor']['post']['summary'] = 'The anchor, described again.';

    $differ = new DocumentDiffer;
    $added = $differ->diff(UirDocument::fromArray($alone), UirDocument::fromArray($beside));
    $removed = $differ->diff(UirDocument::fromArray($beside), UirDocument::fromArray($alone));
    $widenedChanges = $differ->diff(UirDocument::fromArray($alone), UirDocument::fromArray($widened));
    $edited = $differ->diff(UirDocument::fromArray($alone), UirDocument::fromArray($resummarised));

    $codes = static fn ($changeset): array => array_map(static fn ($change): string => $change->code.' '.$change->path, $changeset->changes);

    expect($codes($added))->toContain('operation.added POST webhooks.locality.neighbour')
        ->and($codes($removed))->toContain('operation.removed POST webhooks.locality.neighbour')
        // Editing a webhook is one operation changed, not a pair swapped — which is what the identity
        // minted for it buys, and what the differ has always expected to find there.
        ->and($codes($edited))->toBe(['operation.summary-changed POST webhooks.locality.anchor'])
        // Its payload is a $ref like any other body, so widening it is reported where the shape lives.
        ->and($codes($widenedChanges))->toContain('schema.property-added components.schemas.Anchor.properties.occurredAt')
        ->and(array_filter($codes($widenedChanges), static fn (string $entry): bool => str_starts_with($entry, 'operation.')))->toBe([]);
});

it('keeps a webhook whose payload blows up out of the document, and says which one', function (): void {
    app()->instance(TypeEngine::class, new ThrowingTypeEngine(
        WorkbenchEngine::make(),
        throwingClass: Anchor::class,
        message: 'the analyzer gave up',
    ));

    $result = generateDocument(withWebhooksIn('tests/Fixtures/Webhooks/Locality'));
    $failures = diagnosticsCoded($result->diagnostics, 'webhook.build-failed');

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->severity->value)->toBe('error')
        ->and($failures[0]->message)->toContain('locality.anchor', 'the analyzer gave up')
        // The webhook beside it is documented as if nothing happened.
        ->and(array_keys($result->document->toArray()['webhooks']))->toBe(['locality.neighbour']);
});

it('reports a webhook directory it cannot read, and documents everything else', function (string $dir, string $code): void {
    bindStubEngine();

    $result = generateDocument(withWebhooksIn($dir));

    expect(diagnosticsCoded($result->diagnostics, $code))->toHaveCount(1)
        ->and($result->document->toArray())->not->toHaveKey('webhooks')
        ->and($result->document->toArray()['paths'])->not->toBeEmpty();
})->with([
    'a directory that is not there' => ['app/NoSuchWebhooks', 'webhook.dir-missing'],
    'a relative path that climbs out of the application' => ['../../../etc', 'webhook.dir-escapes-base'],
]);

it('trusts an absolute webhook directory the way it trusts an absolute content directory', function (): void {
    bindStubEngine();

    // Outside the application, so it survives config relativisation as written. Developer-authored
    // config is trusted; only a RELATIVE path is confined, which is what the escape check is for.
    $dir = sys_get_temp_dir().'/docuccino-webhooks-'.uniqid('', true);
    mkdir($dir, 0777, true);

    try {
        $result = generateDocument(withWebhooksIn($dir));

        expect(diagnosticsCoded($result->diagnostics, 'webhook.dir-escapes-base'))->toBe([])
            ->and(diagnosticsCoded($result->diagnostics, 'webhook.dir-missing'))->toBe([])
            ->and($result->document->toArray())->not->toHaveKey('webhooks');
    } finally {
        rmdir($dir);
    }
});
