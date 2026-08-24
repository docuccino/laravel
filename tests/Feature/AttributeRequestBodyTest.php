<?php

declare(strict_types=1);

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Extensions\AttributeRequestBodyExtension;
use Docuccino\Laravel\Integrations\FormRequest\ValidationRequestExtension;
use Docuccino\Laravel\Integrations\LaravelActions\ActionValidationExtension;
use Docuccino\Laravel\Integrations\SpatieData\DataRequestExtension;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Registry\ExtensionRegistry;

/**
 * `#[BodyParameter]` semantics (docs §1.5): the attribute PATCHES a single property of the inferred
 * request body — its named property is added/overridden while every inferred sibling is kept — rather
 * than replacing the whole body. With no inferred body the attributes create one. Regression guard for
 * the historical whole-body replacement.
 */
function runBodyParameters(array $attributes, ?callable $seedInferred = null): array
{
    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/things'),
        actionRef: new ActionRef('', null, 'store'),
        attributes: new AttributeSet($attributes),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
        ),
    );

    $operation = new OperationDraft;
    if ($seedInferred !== null) {
        $seedInferred($operation);
    }
    (new AttributeRequestBodyExtension)->handle($operation, $context);

    /** @var array<string, mixed> $body */
    $body = $operation->resolvedField('requestBody') ?? [];

    return $body;
}

it('patches one inferred body property while keeping inferred siblings', function (): void {
    // A body inferred at the integration layer (e.g. a FormRequest), carrying two properties.
    $seed = function (OperationDraft $operation): void {
        $operation->set('requestBody', [
            'required' => true,
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'body' => ['type' => 'string'],
                ],
                'required' => ['title'],
            ]]],
        ], Contribution::integration('form-request'));
    };

    $body = runBodyParameters([
        new BodyParameter(name: 'title', type: 'int', description: 'The numeric id', required: true),
    ], $seed);

    $schema = $body['content']['application/json']['schema'];

    // The inferred sibling `body` is untouched...
    expect($schema['properties']['body'])->toBe(['type' => 'string'])
        // ...while `title` is overridden by the attribute (now int + description).
        ->and($schema['properties']['title'])->toBe(['type' => 'integer', 'description' => 'The numeric id'])
        // Both keys survive — the whole body was NOT replaced.
        ->and(array_keys($schema['properties']))->toBe(['title', 'body'])
        ->and($schema['required'])->toContain('title');
});

it('puts an attribute format on the body property beside its type', function (): void {
    $body = runBodyParameters([
        new BodyParameter(name: 'due_at', type: 'string', format: 'date-time'),
    ]);

    expect($body['content']['application/json']['schema']['properties']['due_at'])
        ->toBe(['type' => 'string', 'format' => 'date-time']);
});

it('creates a request body from attributes when none was inferred', function (): void {
    $body = runBodyParameters([
        new BodyParameter(name: 'note', description: 'A free-text note', required: true),
    ]);

    $schema = $body['content']['application/json']['schema'];

    expect($body['required'])->toBeTrue()
        ->and($schema)->toBe([
            'type' => 'object',
            'properties' => ['note' => ['type' => 'string', 'description' => 'A free-text note']],
            'required' => ['note'],
        ]);
});

/**
 * The patch above can only keep siblings it can already read, so the attribute extension's position
 * behind every body recoverer is the invariant that makes it a patch at all. Read off the resolved,
 * sorted Request phase rather than off the source, because priority — not the registration list — is
 * what settles it; the count is asserted so a scan that stopped seeing the recoverers fails loudly.
 */
it('sorts the attribute body extension behind every built-in request-body recoverer', function (): void {
    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $document = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');
    $resolved = app(ExtensionRegistry::class)->resolve(app(), DefaultExtensions::all($document), []);

    $order = array_map(
        static fn (object $extension): string => $extension::class,
        $resolved->operationExtensionsFor(OperationPhase::Request),
    );

    $recoverers = [
        ValidationRequestExtension::class,
        DataRequestExtension::class,
        ActionValidationExtension::class,
    ];

    $attribute = array_search(AttributeRequestBodyExtension::class, $order, true);
    expect($attribute)->toBeInt();

    foreach ($recoverers as $recoverer) {
        $position = array_search($recoverer, $order, true);

        expect($position)->toBeInt()
            ->and($position)->toBeLessThan($attribute);
    }
});
