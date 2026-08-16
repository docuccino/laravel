<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Laravel\Extensions\FrameworkResponseTypeToSchema;
use Docuccino\Laravel\Support\FrameworkClasses;
use Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\CustomJsonResponse;

/**
 * A framework response object is transport, not an API contract. These pin the two halves of that:
 * the guard that keeps every class in the family out of `components.schemas`, and what the response
 * side documents instead — a redirect's 3xx + `Location`, a bare `JsonResponse`'s open JSON body, and
 * an announced diagnostic either way rather than a silent loss.
 *
 * The FQCNs come from {@see FrameworkClasses::RESPONSE_CLASSES}; that each is a type the engine really
 * hands back is proven separately, against the real analyser, in FrameworkResponseReachabilityTest.
 */

/**
 * Metadata the real engine returns for a response object: the members `ClassTypeToSchema` would hoist
 * if nothing stopped it. Seeded so the guard is proven to refuse a class it COULD have described.
 */
function responseObjectMetadata(string $fqcn): ClassMetadata
{
    return new ClassMetadata($fqcn, [
        new PropertyMetadata('original', new UnknownT('mixed'), 'The original content of the response.'),
        new PropertyMetadata('headers', new ScalarT(ScalarT::STRING)),
    ]);
}

it('never hoists a framework response class as a component', function (string $fqcn): void {
    [, $document] = documentForReturn(new ClassT($fqcn), [$fqcn => responseObjectMetadata($fqcn)]);

    expect(typeSchemas($document))->toBe([]);
})->with(FrameworkClasses::RESPONSE_CLASSES);

it('recognises every listed framework response class', function (string $fqcn): void {
    expect(FrameworkClasses::isResponse($fqcn))->toBeTrue();
})->with(FrameworkClasses::RESPONSE_CLASSES);

it('recognises an app response subclass the list does not name', function (): void {
    // The list is deliberately not exhaustive: a loadable subclass of the Symfony base counts too,
    // which is what covers `class ApiResponse extends JsonResponse` in a real app.
    expect(FrameworkClasses::RESPONSE_CLASSES)->not->toContain(CustomJsonResponse::class)
        ->and(FrameworkClasses::isResponse(CustomJsonResponse::class))->toBeTrue();

    [, $document] = documentForReturn(
        new ClassT(CustomJsonResponse::class),
        [CustomJsonResponse::class => responseObjectMetadata(CustomJsonResponse::class)],
    );

    expect(typeSchemas($document))->toBe([]);
});

it('leaves a class that is not a framework response to hoist normally', function (): void {
    // The unknown-entry contract. The guard has to be surgical: anything outside the family still
    // reflects into a component of its own.
    $fqcn = 'Workbench\\App\\Data\\ReceiptData';
    expect(FrameworkClasses::isResponse($fqcn))->toBeFalse();

    [$responses, $document] = documentForReturn(new ClassT($fqcn), [
        $fqcn => new ClassMetadata($fqcn, [new PropertyMetadata('total', new ScalarT(ScalarT::INT))]),
    ]);

    expect($responses['200']['content']['application/json']['schema']['$ref'] ?? null)
        ->toBe('#/components/schemas/ReceiptData')
        ->and($document['components']['schemas']['ReceiptData']['properties'] ?? null)->toBe(['total' => ['type' => 'integer']]);
});

it('keeps a hoisted class the framework happens to share a name with in view', function (): void {
    // About the assertions above rather than the guard: `typeSchemas()` hides the shared error shapes so
    // that `toBe([])` means "nothing hoisted", and an application class called `NotFound` must not be
    // one of them. Hidden by name it would be, and every `toBe([])` in this file would then pass over a
    // component that really was hoisted.
    $fqcn = 'Workbench\\App\\Data\\NotFound';

    [, $document] = documentForReturn(new ClassT($fqcn), [
        $fqcn => new ClassMetadata($fqcn, [new PropertyMetadata('reference', new ScalarT(ScalarT::STRING))]),
    ]);

    // The registry got there first, so the class keeps the plain name and the framework's 404 shape
    // climbs past it — and only the climbed one is filtered, because only the 404s reach it.
    expect(array_keys(typeSchemas($document)))->toBe(['NotFound'])
        ->and(array_keys($document['components']['schemas']))->toHaveCount(2);
});

it('refuses a framework response wherever it turns up, not just at the top level', function (): void {
    // A `JsonResponse|RedirectResponse` return reaches the converter as a union, and a response object
    // can just as well be a property of a class being expanded. The refusal is a mapper, so it holds
    // for every position — an open `{}` branch instead of a reflected component.
    $converter = new SchemaConverter(
        [new FrameworkResponseTypeToSchema, ...DefaultTypeMappers::all()],
        new NullTypeEngine,
        $components = new ComponentRegistry,
    );

    $schema = $converter->toSchema(UnionT::of([
        new ClassT(FrameworkClasses::JSON_RESPONSE),
        new ClassT(FrameworkClasses::REDIRECT_RESPONSE),
    ]));

    expect($schema->schema)->toBe(['anyOf' => [[], []]])
        ->and($components->schemas())->toBe([]);
});

it('defers on a type it does not support', function (): void {
    // The chain contract: a mapper handed something outside its supports() hands the type straight back
    // so the next one gets it, rather than swallowing it as an open schema.
    $mapper = new FrameworkResponseTypeToSchema;
    $type = new ScalarT(ScalarT::STRING);

    expect($mapper->supports($type))->toBeFalse()
        ->and($mapper->toSchema($type, new SchemaConverter([], new NullTypeEngine, new ComponentRegistry)))->toBeNull();
});

it('documents a redirect as a 3xx with a Location header and no body', function (): void {
    // Laravel's RedirectResponse defaults to 302 but takes any 3xx, and nothing at the return site
    // states which — so the OAS range key is what the code proves. The Location header is not a guess:
    // every redirect response sets it.
    [$responses, $document] = documentForReturn(
        new ClassT(FrameworkClasses::REDIRECT_RESPONSE),
        [FrameworkClasses::REDIRECT_RESPONSE => responseObjectMetadata(FrameworkClasses::REDIRECT_RESPONSE)],
    );

    expect(array_keys($responses))->toBe(['3XX'])
        ->and($responses['3XX'])->not->toHaveKey('content')
        ->and($responses['3XX']['headers'])->toBe([
            'Location' => [
                'description' => 'The URL to follow.',
                'schema' => ['type' => 'string', 'format' => 'uri-reference'],
            ],
        ])
        ->and($responses['3XX']['description'])->toBe('Redirect')
        ->and(typeSchemas($document))->toBe([]);
});

it('raises the pin-the-status advice as a diagnostic, never in the published description', function (): void {
    // A description is read by the API's CONSUMERS, who cannot act on advice about a codebase they
    // can't see; the author can, so the advice is a diagnostic.
    [$responses, , $diagnostics] = documentForReturn(new ClassT(FrameworkClasses::REDIRECT_RESPONSE));

    expect($responses['3XX']['description'])->not->toContain('#[Response');

    $raised = array_values(array_filter(
        $diagnostics,
        static fn ($d): bool => $d->code === 'inferred-response.unpinned-redirect' && $d->routeSignature === 'GET /api/forms',
    ));

    expect($raised)->toHaveCount(1)
        ->and($raised[0]->severity->value)->toBe('info')
        ->and($raised[0]->message)->toContain('3XX')
        ->and($raised[0]->help)->toContain('#[Response(302)]');
});

it('raises no unpinned-redirect diagnostic for a route that never redirects', function (): void {
    [, , $diagnostics] = documentForReturn(new ClassT(FrameworkClasses::JSON_RESPONSE));

    expect(array_map(static fn ($d): string => $d->code, $diagnostics))
        ->not->toContain('inferred-response.unpinned-redirect');
});

it('carries the 3XX range key through validation and every OpenAPI version', function (): void {
    // A range key is legal in OAS 3.0, 3.1 and 3.2 alike, so nothing downlevels it away — and the
    // build's own schema validation has to accept the document that carries it, or the honest redirect
    // would arrive as a validation failure instead.
    [, , $diagnostics, $result] = documentForReturn(new ClassT(FrameworkClasses::REDIRECT_RESPONSE));

    expect(array_map(static fn ($d): string => $d->code, $diagnostics))->not->toContain('document.schema-invalid');

    foreach ([new OpenApi32Emitter, new OpenApi31DownlevelEmitter, new OpenApi30DownlevelEmitter] as $emitter) {
        expect($emitter->emit($result->document))->toContain('"3XX"');
    }
});

it('does not diagnose a redirect, which loses nothing', function (): void {
    [, , $diagnostics] = documentForReturn(new ClassT(FrameworkClasses::REDIRECT_RESPONSE));

    expect(array_map(static fn ($d): string => $d->code, $diagnostics))
        ->not->toContain('inferred-response.payload-unrecoverable');
});

it('gives a bare JsonResponse an open JSON body and says so out loud', function (): void {
    // The class proves the media type and nothing else. An open `{}` under application/json is true;
    // an absent content entry would read as "no body", which the class contradicts.
    [$responses, $document, $diagnostics] = documentForReturn(
        new ClassT(FrameworkClasses::JSON_RESPONSE),
        [FrameworkClasses::JSON_RESPONSE => responseObjectMetadata(FrameworkClasses::JSON_RESPONSE)],
    );

    expect($responses['200']['content']['application/json']['schema'])->toBe([])
        ->and(typeSchemas($document))->toBe([]);

    $raised = array_values(array_filter(
        $diagnostics,
        static fn ($d): bool => $d->code === 'inferred-response.payload-unrecoverable' && $d->routeSignature === 'GET /api/forms',
    ));

    expect($raised)->toHaveCount(1)
        ->and($raised[0]->severity->value)->toBe('info')
        ->and($raised[0]->message)->toContain('Illuminate\\Http\\JsonResponse')
        ->and($raised[0]->help)->toContain('#[Response(');
});

it('documents only the status for a framework response with no provable media type', function (): void {
    // A file download or a stream carries a body, but neither its media type nor its shape is stated
    // anywhere — so claiming `application/json` would be a confident wrong answer.
    $fqcn = 'Symfony\\Component\\HttpFoundation\\BinaryFileResponse';

    [$responses, $document, $diagnostics] = documentForReturn(
        new ClassT($fqcn),
        [$fqcn => responseObjectMetadata($fqcn)],
    );

    expect($responses['200'])->not->toHaveKey('content')
        ->and(typeSchemas($document))->toBe([])
        ->and(array_map(static fn ($d): string => $d->code, $diagnostics))
        ->toContain('inferred-response.payload-unrecoverable');
});

it('still documents the payload of a JsonResponse whose generic was recovered', function (): void {
    // The guard must not cost a recovered body anything: `JsonResponse<payload, status>` still unwraps,
    // and it is the payload — never the wrapper — that reaches the converter.
    $payload = new ArrayShapeT([
        new ArrayShapeField('id', new ScalarT(ScalarT::INT)),
        new ArrayShapeField('name', new ScalarT(ScalarT::STRING)),
    ]);

    [$responses, $document] = documentForReturn(new ClassT(FrameworkClasses::JSON_RESPONSE, [
        $payload,
        new LiteralT(201),
    ]));

    $schema = $responses['201']['content']['application/json']['schema'];
    unset($schema['x-docuccino']);

    expect(array_keys($responses))->toBe([201])
        ->and($schema)->toBe([
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']],
            'required' => ['id', 'name'],
        ])
        ->and(typeSchemas($document))->toBe([]);
});

it('recognises the redirect family and nothing else', function (string $fqcn, bool $expected): void {
    expect(FrameworkClasses::isRedirect($fqcn))->toBe($expected);
})->with([
    'illuminate redirect' => [FrameworkClasses::REDIRECT_RESPONSE, true],
    'symfony redirect' => [FrameworkClasses::REDIRECT_BASE, true],
    'json response' => [FrameworkClasses::JSON_RESPONSE, false],
    'symfony response base' => [FrameworkClasses::RESPONSE_BASE, false],
    'illuminate response' => ['Illuminate\\Http\\Response', false],
    'binary file' => ['Symfony\\Component\\HttpFoundation\\BinaryFileResponse', false],
    'streamed' => ['Symfony\\Component\\HttpFoundation\\StreamedResponse', false],
    'app subclass' => [CustomJsonResponse::class, false],
    'not a class at all' => ['App\\Nope\\Missing', false],
]);
