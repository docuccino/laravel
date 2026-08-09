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
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Integrations\ProblemDetails\ProblemDetailsExceptionToResponse;
use Docuccino\Laravel\Integrations\ProblemDetails\ProblemDetailsSchema;

/**
 * The RFC 9457 Problem Details preset (design §6): config-activated, every framework exception maps
 * to a reusable `application/problem+json` response built on one shared `ProblemDetails` component.
 * The table is dataset-driven over EVERY entry; the preset stays inert when a document did not opt in.
 */
function problemContext(string $errorResponses = 'problem-details'): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/x'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet,
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', [], errorResponses: $errorResponses),
    );
}

function problemThrow(string $fqcn, ?int $status = null): ThrownException
{
    return new ThrownException($fqcn, $status, [], ThrowConfidence::Certain, ThrowDisposition::Signal);
}

it('maps every framework exception to its reusable problem response + shared schema', function (string $fqcn, array $entry): void {
    $mapper = new ProblemDetailsExceptionToResponse;
    $context = problemContext();
    $components = new ComponentRegistry;
    $throw = problemThrow($fqcn);

    expect($mapper->supports($throw, $context))->toBeTrue()
        ->and($mapper->producer())->toBe('integration:problem-details');

    $response = $mapper->toResponse($throw, $context, $components);
    expect($response)->not->toBeNull()
        ->and($response->status)->toBe($entry['status'])
        ->and($response->freeze()->ref)->toBe('#/components/responses/'.$entry['component']);

    // The shared ProblemDetails schema and the per-status response component are hoisted.
    expect($components->schemas())->toHaveKey(ProblemDetailsSchema::SCHEMA_NAME)
        ->and($components->responses())->toHaveKey($entry['component']);

    $component = $components->responses()[$entry['component']];
    expect($component['content'])->toHaveKey('application/problem+json')
        ->and($component['content']['application/problem+json']['example']['status'] ?? null)->toBe((int) $entry['status']);
})->with(array_map(
    static fn (string $fqcn, array $entry): array => [$fqcn, $entry],
    array_keys(ProblemDetailsSchema::table()),
    array_values(ProblemDetailsSchema::table()),
));

it('grafts the errors map onto the validation problem via allOf', function (): void {
    $components = new ComponentRegistry;
    (new ProblemDetailsExceptionToResponse)->toResponse(
        problemThrow('Illuminate\\Validation\\ValidationException'),
        problemContext(),
        $components,
    );

    $schema = $components->responses()['ProblemValidation']['content']['application/problem+json']['schema'];
    expect($schema)->toHaveKey('allOf')
        ->and($schema['allOf'][0])->toBe(['$ref' => '#/components/schemas/ProblemDetails'])
        ->and($schema['allOf'][1]['properties']['errors']['additionalProperties'] ?? null)
        ->toBe(['type' => 'array', 'items' => ['type' => 'string']]);
});

it('documents a bare HttpException under a per-status problem response', function (): void {
    $mapper = new ProblemDetailsExceptionToResponse;
    $components = new ComponentRegistry;
    $throw = problemThrow('Symfony\\Component\\HttpKernel\\Exception\\HttpException', 409);

    expect($mapper->supports($throw, problemContext()))->toBeTrue();
    $response = $mapper->toResponse($throw, problemContext(), $components);

    expect($response?->status)->toBe('409')
        ->and($response?->freeze()->ref)->toBe('#/components/responses/Problem409')
        ->and($components->responses())->toHaveKey('Problem409');
});

it('declines a bare HttpException with no folded status', function (): void {
    $mapper = new ProblemDetailsExceptionToResponse;
    $throw = problemThrow('Symfony\\Component\\HttpKernel\\Exception\\HttpException', null);

    expect($mapper->supports($throw, problemContext()))->toBeFalse();
});

it('stays inert unless the document opts into the preset', function (): void {
    $mapper = new ProblemDetailsExceptionToResponse;
    $throw = problemThrow('Illuminate\\Auth\\AuthenticationException');

    expect($mapper->supports($throw, problemContext('default')))->toBeFalse()
        ->and($mapper->supports($throw, problemContext('none')))->toBeFalse()
        ->and($mapper->supports($throw, problemContext('problem-details')))->toBeTrue();
});

it('references one shared ProblemDetails schema from many operations through the pipeline', function (): void {
    bindStubEngine();

    $document = generateDocument(static function (array $raw): array {
        $raw['error_responses'] = 'problem-details';

        return $raw;
    })->document->toArray();

    // The /api/forms/{form} 404 is a $ref to the shared ProblemNotFound response.
    $response = $document['paths']['/api/forms/{form}']['get']['responses']['404'] ?? [];
    expect($response['$ref'] ?? null)->toBe('#/components/responses/ProblemNotFound');

    // The shared schema + response components are hoisted exactly once.
    expect($document['components']['schemas'])->toHaveKey('ProblemDetails')
        ->and($document['components']['responses'])->toHaveKey('ProblemNotFound')
        ->and($document['components']['responses']['ProblemNotFound']['content'])
        ->toHaveKey('application/problem+json');
});

it('models the 422 errors as a field map by default and a JSON-pointer list on request', function (): void {
    $entry = ProblemDetailsSchema::table()['Illuminate\\Validation\\ValidationException'];
    $ref = ['$ref' => '#/components/schemas/'.ProblemDetailsSchema::SCHEMA_NAME];
    $media = ProblemDetailsSchema::MEDIA_TYPE;

    // Default 'map': field → message-list, matching Laravel's stock validation JSON.
    $map = ProblemDetailsSchema::response($entry, $ref);
    expect($map['content'][$media]['schema']['allOf'][1]['properties']['errors'])
        ->toBe(['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]])
        ->and($map['content'][$media]['example']['errors'])->toBe(['field' => ['The field is invalid.']]);

    // 'pointer-list': an array of {detail, pointer} objects.
    $list = ProblemDetailsSchema::response($entry, $ref, 'pointer-list');
    $errors = $list['content'][$media]['schema']['allOf'][1]['properties']['errors'];
    expect($errors['type'])->toBe('array')
        ->and($errors['items']['properties'])->toHaveKeys(['detail', 'pointer'])
        ->and($errors['items']['required'])->toBe(['detail', 'pointer'])
        ->and($list['content'][$media]['example']['errors'])->toBe([['detail' => 'The field is invalid.', 'pointer' => '#/field']]);
});

it('parses the error_responses bag into a preset + errors shape', function (): void {
    $bag = app(DocumentConfigFactory::class)->make('default', ['error_responses' => ['preset' => 'problem-details', 'errors_shape' => 'pointer-list']], 'skeleton');
    expect($bag->errorResponses)->toBe('problem-details')
        ->and($bag->errorsShape)->toBe('pointer-list');

    // The string form keeps working and defaults the shape to 'map'.
    $string = app(DocumentConfigFactory::class)->make('default', ['error_responses' => 'problem-details'], 'skeleton');
    expect($string->errorResponses)->toBe('problem-details')
        ->and($string->errorsShape)->toBe('map');

    // An unknown errors_shape value degrades to the default 'map' rather than being carried through.
    $invalid = app(DocumentConfigFactory::class)->make('default', ['error_responses' => ['preset' => 'problem-details', 'errors_shape' => 'wibble']], 'skeleton');
    expect($invalid->errorResponses)->toBe('problem-details')
        ->and($invalid->errorsShape)->toBe('map');
});
