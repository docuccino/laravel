<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Laravel\Integrations\FrameworkErrors\FrameworkErrorsExceptionToResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Tier 2 of the error-response chain (design §6): the framework-default JSON shapes. The mapping
 * table is dataset-driven over EVERY entry (coverage standard), an unknown exception degrades to a
 * declined mapper, and the real /api/forms/{form} 404 flows through the pipeline under the tier's
 * producer.
 */
function frameworkErrorContext(): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/x'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet,
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', [], errorResponses: 'default'),
    );
}

function frameworkThrow(string $fqcn): ThrownException
{
    return new ThrownException($fqcn, null, [], ThrowConfidence::Certain, ThrowDisposition::Signal);
}

it('maps every framework exception to its stock status + shape', function (string $fqcn, array $entry): void {
    $mapper = new FrameworkErrorsExceptionToResponse;
    $context = frameworkErrorContext();
    $throw = frameworkThrow($fqcn);

    expect($mapper->supports($throw, $context))->toBeTrue()
        ->and($mapper->producer())->toBe('integration:framework-errors');

    $response = $mapper->toResponse($throw, $context, new ComponentRegistry);
    expect($response)->not->toBeNull();

    $frozen = $response->freeze();
    expect($response->status)->toBe($entry['status'])
        ->and($frozen->description)->toBe($entry['description'])
        ->and($frozen->content['application/json']['schema']['type'] ?? null)->toBe('object');

    $properties = $frozen->content['application/json']['schema']['properties'] ?? [];
    foreach (array_keys($entry['shape']['properties']) as $property) {
        expect($properties)->toHaveKey($property);
    }
})->with(array_map(
    static fn (string $fqcn, array $entry): array => [$fqcn, $entry],
    array_keys(FrameworkErrorsExceptionToResponse::table()),
    array_values(FrameworkErrorsExceptionToResponse::table()),
));

it('grafts the field-keyed errors map onto the 422 body', function (): void {
    $mapper = new FrameworkErrorsExceptionToResponse;
    $response = $mapper->toResponse(
        frameworkThrow('Illuminate\\Validation\\ValidationException'),
        frameworkErrorContext(),
        new ComponentRegistry,
    );

    $schema = $response?->freeze()->content['application/json']['schema'] ?? [];
    expect($schema['properties']['errors']['type'] ?? null)->toBe('object')
        ->and($schema['properties']['errors']['additionalProperties'] ?? null)
        ->toBe(['type' => 'array', 'items' => ['type' => 'string']]);
});

it('inherits a mapped base exception shape for a subclass', function (): void {
    $mapper = new FrameworkErrorsExceptionToResponse;
    // A ModelNotFoundException subclass still resolves to the 404 entry (subtype-aware match).
    $subclass = new class('') extends ModelNotFoundException {};

    expect($mapper->supports(frameworkThrow($subclass::class), frameworkErrorContext()))->toBeTrue();
});

it('declines an exception outside the framework-defaults table', function (): void {
    $mapper = new FrameworkErrorsExceptionToResponse;

    expect($mapper->supports(frameworkThrow('App\\Exceptions\\PaymentRequiredException'), frameworkErrorContext()))
        ->toBeFalse();
});

it('documents the real 404 under the framework-errors producer through the pipeline', function (): void {
    bindStubEngine();

    $document = generateDocument()->document->toArray();
    $response = $document['paths']['/api/forms/{form}']['get']['responses']['404'] ?? [];

    $producers = array_map(
        static fn (array $record): string => $record['producer'],
        $response['x-docuccino']['provenance'] ?? [],
    );

    expect($producers)->toContain('integration:framework-errors')
        ->and(resolveResponse($document, $response)['content']['application/json']['schema']['properties'] ?? [])->toHaveKey('message');
});
