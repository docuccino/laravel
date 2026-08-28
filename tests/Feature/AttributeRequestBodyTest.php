<?php

declare(strict_types=1);

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
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
function runBodyParameters(array $attributes, ?callable $seedInferred = null, ?array &$diagnostics = null): array
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

    $diagnostics = $context->components->diagnostics();

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

/*
 * The name is a field PATH, read the way the producers of the very body it patches read one: a `.`
 * descends, a `*` names an element, and `\.` is a dot belonging to the field name. Before this it was
 * a literal map key, so `meta.validation_overrides` published a top-level property beside `meta` that
 * no endpoint accepts — a name the document could not carry, written into it unchecked.
 *
 * Every row asserts the WHOLE property map and the exact diagnostic codes, so a path that lands
 * somewhere else, or a refusal that half-builds a container on its way out, fails here.
 */
it('places a #[BodyParameter] where its name says, or refuses to and says why', function (array $seeded, string $name, array $expected, ?string $code): void {
    $seed = $seeded === [] ? null : function (OperationDraft $operation) use ($seeded): void {
        $operation->set('requestBody', [
            'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $seeded]]],
        ], Contribution::integration('form-request'));
    };

    $diagnostics = [];
    $body = runBodyParameters([new BodyParameter(name: $name, type: 'string')], $seed, $diagnostics);

    expect($body['content']['application/json']['schema']['properties'])->toBe($expected)
        ->and(array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, $diagnostics))
        ->toBe($code === null ? [] : [$code]);
})->with([
    'a plain name is one top-level property' => [
        [], 'nickname', ['nickname' => ['type' => 'string']], null,
    ],
    'a dotted name nests into the object it names' => [
        ['meta' => ['type' => 'object', 'properties' => ['locale' => ['type' => 'string']]]],
        'meta.validation_overrides',
        ['meta' => ['type' => 'object', 'properties' => [
            'locale' => ['type' => 'string'],
            'validation_overrides' => ['type' => 'string'],
        ]]],
        null,
    ],
    'a missing parent is created as the object the path says it is' => [
        [], 'meta.validation_overrides',
        ['meta' => ['type' => 'object', 'properties' => ['validation_overrides' => ['type' => 'string']]]],
        null,
    ],
    'a deep path creates every container on the way' => [
        [], 'a.b.c',
        ['a' => ['type' => 'object', 'properties' => [
            'b' => ['type' => 'object', 'properties' => ['c' => ['type' => 'string']]],
        ]]],
        null,
    ],
    // Nesting into a `$ref` would put the property on the component, and every other operation using
    // that component would publish it too — one action's declaration leaking into operations that
    // never made it.
    'a shared component parent is refused, untouched' => [
        ['meta' => ['$ref' => '#/components/schemas/Meta']],
        'meta.validation_overrides',
        ['meta' => ['$ref' => '#/components/schemas/Meta']],
        'attribute.body-parameter-parent',
    ],
    'a scalar parent is refused rather than overwritten' => [
        ['meta' => ['type' => 'string']],
        'meta.validation_overrides',
        ['meta' => ['type' => 'string']],
        'attribute.body-parameter-parent',
    ],
    'a composed parent is refused, because no one branch owns the field' => [
        ['meta' => ['allOf' => [['type' => 'object']]]],
        'meta.validation_overrides',
        ['meta' => ['allOf' => [['type' => 'object']]]],
        'attribute.body-parameter-parent',
    ],
    'an element of a non-array is refused' => [
        ['meta' => ['type' => 'object']],
        'meta.*',
        ['meta' => ['type' => 'object']],
        'attribute.body-parameter-parent',
    ],
    'a `*` leaf describes the element of the array it names' => [
        ['tags' => ['type' => 'array', 'items' => ['type' => 'integer']]],
        'tags.*',
        ['tags' => ['type' => 'array', 'items' => ['type' => 'string']]],
        null,
    ],
    'a `*` segment descends into the element' => [
        [], 'items.*.id',
        ['items' => ['type' => 'array', 'items' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string']],
        ]]],
        null,
    ],
    'an escaped dot is part of the name rather than a descent' => [
        [], 'meta\.validation_overrides',
        ['meta.validation_overrides' => ['type' => 'string']],
        null,
    ],
    'a trailing dot names nothing' => [
        [], 'meta.', [], 'attribute.body-parameter-name',
    ],
    'a doubled dot names nothing' => [
        [], 'meta..validation_overrides', [], 'attribute.body-parameter-name',
    ],
    'a leading dot names nothing' => [
        [], '.meta', [], 'attribute.body-parameter-name',
    ],
    'an empty name names nothing' => [
        [], '', [], 'attribute.body-parameter-name',
    ],
]);

it('marks a nested field required on the object that holds it, and the body with it', function (): void {
    $diagnostics = [];
    $body = runBodyParameters([
        new BodyParameter(name: 'meta.validation_overrides', type: 'string', required: true),
    ], null, $diagnostics);

    $schema = $body['content']['application/json']['schema'];

    // `required` is per-object, so it belongs on `meta` — not on the body, where it would name a
    // top-level property that isn't there. The body itself is required all the same: a field the
    // server insists on cannot arrive without one.
    expect($schema['properties']['meta'])->toBe([
        'type' => 'object',
        'properties' => ['validation_overrides' => ['type' => 'string']],
        'required' => ['validation_overrides'],
    ])
        ->and($schema)->not->toHaveKey('required')
        ->and($body['required'])->toBeTrue()
        ->and($diagnostics)->toBe([]);
});

it('leaves the body optional when the only path it could not carry was the required one', function (): void {
    $seed = function (OperationDraft $operation): void {
        $operation->set('requestBody', [
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['meta' => ['$ref' => '#/components/schemas/Meta']],
            ]]],
        ], Contribution::integration('form-request'));
    };

    $diagnostics = [];
    $body = runBodyParameters([
        new BodyParameter(name: 'meta.validation_overrides', type: 'string', required: true),
    ], $seed, $diagnostics);

    // Nothing was documented, so nothing is required because of it.
    expect($body)->not->toHaveKey('required')
        ->and($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($diagnostics[0]->message)->toContain('nests under `meta`, documented as a reference to a shared component')
        ->and($diagnostics[0]->help)->toContain('Declare it where the component is defined');
});

it('names the container and what it is documented as when a path cannot land', function (): void {
    $seed = function (OperationDraft $operation): void {
        $operation->set('requestBody', [
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                // No `type` of its own: a schema that claims nothing contradicts nothing, so the path
                // is free to read it as the object it says it is — and free to walk past it.
                'properties' => ['meta' => [
                    'description' => 'Extra metadata.',
                    'properties' => ['locale' => ['type' => 'string']],
                ]],
            ]]],
        ], Contribution::integration('form-request'));
    };

    $diagnostics = [];
    $body = runBodyParameters([
        new BodyParameter(name: 'meta.locale.region', type: 'string'),
    ], $seed, $diagnostics);

    // The refusal names the ANCESTOR that could not carry it, not the whole path — `meta` was fine.
    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('attribute.body-parameter-parent')
        ->and($diagnostics[0]->message)->toContain('nests under `meta.locale`, documented as `string`')
        // And `meta` kept the shape it had, `type` included: a declaration that documented nothing
        // leaves no half-built container behind to say it nearly did.
        ->and($body['content']['application/json']['schema']['properties']['meta'])
        ->toBe(['description' => 'Extra metadata.', 'properties' => ['locale' => ['type' => 'string']]]);
});

it('applies a parent declaration before a child one, whichever order they were written in', function (): void {
    $diagnostics = [];
    $body = runBodyParameters([
        // The child is written FIRST. Applied in source order, the `meta` below would replace it.
        new BodyParameter(name: 'meta.validation_overrides', type: 'string'),
        new BodyParameter(name: 'meta', type: 'object', description: 'Extra metadata.'),
    ], null, $diagnostics);

    expect($body['content']['application/json']['schema']['properties']['meta'])->toBe([
        'description' => 'Extra metadata.',
        'type' => 'object',
        'properties' => ['validation_overrides' => ['type' => 'string']],
    ])->and($diagnostics)->toBe([]);
});

it('lets a declared object parent rescue a path a recovered scalar would have refused', function (): void {
    $seed = function (OperationDraft $operation): void {
        $operation->set('requestBody', [
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['meta' => ['type' => 'string']],
            ]]],
        ], Contribution::integration('form-request'));
    };

    $diagnostics = [];
    $body = runBodyParameters([
        new BodyParameter(name: 'meta', type: 'object'),
        new BodyParameter(name: 'meta.validation_overrides', type: 'string'),
    ], $seed, $diagnostics);

    expect($body['content']['application/json']['schema']['properties']['meta'])->toBe([
        'type' => 'object',
        'properties' => ['validation_overrides' => ['type' => 'string']],
    ])->and($diagnostics)->toBe([]);
});
