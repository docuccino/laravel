<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataRequestExtension;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\DescribedInputController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\DescribedInputData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\MappedInputController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\MappedInputData;

/**
 * What an input DTO's own declarations publish on the REQUEST side. A request body is recovered from
 * validation rules, so its fields are named by rules and arrive carrying nothing a property said — the
 * prose has to be matched back on afterwards. Until it was, a docblock written on an input DTO reached
 * the response side and was silently dropped from the request side, and the `example` slot it should
 * have filled held a value synthesized from the field's own keywords instead.
 *
 * Both sides read one source, so both are asserted from one class here: whatever prose a property
 * declares reaches the request schema exactly as it reaches the response schema.
 */
function inputMetadata(): ClassMetadata
{
    // Mirrors the docblocks DescribedInputData really carries — what the engine hands back for it.
    return new ClassMetadata(DescribedInputData::class, [
        new PropertyMetadata('reference', ScalarT::string(), "The caller's own reference for this submission.", 'INV-2291'),
        new PropertyMetadata('blueprint_id', ScalarT::string(), 'The blueprint whose field set this is built from.', '8f14e45f-ceea-467a-9c2e-6f5f0c1a1b2c'),
        new PropertyMetadata('token', ScalarT::string()),
    ]);
}

/** The hoisted request-body component for the fixture DTO, through the real recovery path. */
function requestComponent(): array
{
    $components = new ComponentRegistry;
    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/described-input'),
        actionRef: new ActionRef('', DescribedInputController::class, 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(classes: [DescribedInputData::class => inputMetadata()]),
        document: new DocumentConfig('default', []),
        components: $components,
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            ruleTransformers: ValidationIntegration::transformers(),
        ),
    );

    (new DataRequestExtension)->handle(new OperationDraft, $context);

    /** @var array<string, mixed> $component */
    $component = $components->schemas()['DescribedInputData'] ?? [];

    return is_array($component['properties'] ?? null) ? $component['properties'] : [];
}

/** The same class's RESPONSE component, for the side that always published this prose. */
function responseComponent(): array
{
    $components = new ComponentRegistry;
    $converter = new SchemaConverter(
        [new DataSchema, ...DefaultTypeMappers::all()],
        new StubTypeEngine(classes: [DescribedInputData::class => inputMetadata()]),
        $components,
    );
    $converter->toSchema(new ClassT(DescribedInputData::class));

    /** @var array<string, mixed> $component */
    $component = $components->schemas()['DescribedInputData'] ?? [];

    return is_array($component['properties'] ?? null) ? $component['properties'] : [];
}

it('publishes a property declaration on the request side and the response side alike', function (string $field, string $keyword, mixed $expected): void {
    expect(requestComponent()[$field][$keyword] ?? null)->toBe($expected)
        ->and(responseComponent()[$field][$keyword] ?? null)->toBe($expected);
})->with([
    // Docblock only (precedence 30) — the case a whole application's input DTOs were writing into a void.
    'docblock description' => ['reference', 'description', "The caller's own reference for this submission."],
    'docblock example' => ['reference', 'example', 'INV-2291'],

    // The attribute layer (40) over that docblock, the same way round on both sides.
    'attribute beats docblock, description' => ['blueprint_id', 'description', 'The blueprint this position is built from.'],
    'attribute beats docblock, example' => ['blueprint_id', 'example', '0b4a1d7e-1111-4222-8333-444455556666'],
]);

it('leaves a rule-derived example standing where the property proposes none', function (): void {
    // `token` says nothing, so the value synthesized from its own keywords is all there is — and it is
    // still worth publishing. What changed is only that an author's own example now outranks it.
    $token = requestComponent()['token'];

    expect($token)->toHaveKey('example')
        ->and($token)->not->toHaveKey('description')
        ->and($token['maxLength'])->toBe(32);
});

it('keeps the rules as the source of what the body accepts', function (): void {
    // The prose rides on the rule-named fields; it never adds one, never drops one, and never touches a
    // constraint. A docblock is documentation, not validation.
    $properties = requestComponent();

    expect(array_keys($properties))->toBe(['reference', 'blueprint_id', 'token'])
        ->and($properties['reference']['maxLength'])->toBe(64)
        ->and($properties['blueprint_id']['format'])->toBe('uuid');
});

it('follows a remapped property to the key the request really accepts', function (): void {
    // `#[MapInputName]` renames a field on the way IN, so the property a declaration sits on and the key
    // the body publishes are different names. Looked for under the property name, both the docblock and
    // the attribute land on nothing — silently, since a body that simply lacks a description looks like a
    // body nobody described.
    $components = new ComponentRegistry;
    $metadata = new ClassMetadata(MappedInputData::class, [
        new PropertyMetadata('blueprintId', ScalarT::string(), 'The blueprint whose field set this is built from.'),
        new PropertyMetadata('sourceChannel', ScalarT::string()),
    ]);
    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/mapped-input'),
        actionRef: new ActionRef('', MappedInputController::class, 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(classes: [MappedInputData::class => $metadata]),
        document: new DocumentConfig('default', []),
        components: $components,
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            ruleTransformers: ValidationIntegration::transformers(),
        ),
    );

    (new DataRequestExtension)->handle(new OperationDraft, $context);

    /** @var array<string, mixed> $properties */
    $properties = $components->schemas()['MappedInputData']['properties'];

    // The keys are the mapper's, and each declaration is on the key its own property publishes under.
    expect(array_keys($properties))->toBe(['blueprint_id', 'source_channel'])
        ->and($properties['blueprint_id']['description'])->toBe('The blueprint whose field set this is built from.')
        ->and($properties['source_channel']['description'])->toBe('Where the submission came from.');
});

it('describes the request body component from the class that states it', function (): void {
    // A request body is assembled from rules rather than lifted by the component hoist, so the class's own
    // sentence has to be written where the body is built — otherwise every `*Request` schema in a document
    // is nameless prose-wise while its response twin is described.
    $components = new ComponentRegistry;
    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/described-input'),
        actionRef: new ActionRef('', DescribedInputController::class, 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(classes: [DescribedInputData::class => inputMetadata()]),
        document: new DocumentConfig('default', []),
        components: $components,
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            ruleTransformers: ValidationIntegration::transformers(),
        ),
    );

    (new DataRequestExtension)->handle(new OperationDraft, $context);

    expect($components->schemas()['DescribedInputData']['description'])
        ->toBe('Everything the submission form captured, as one payload.');
});
