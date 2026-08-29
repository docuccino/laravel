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
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Extensions\AttributeRequestBodyExtension;
use Docuccino\Laravel\Integrations\FormRequest\ValidationRequestExtension;
use Docuccino\Laravel\Integrations\LaravelActions\ActionValidationExtension;
use Docuccino\Laravel\Integrations\SpatieData\DataRequestExtension;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
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
        'type' => 'object',
        'additionalProperties' => [],
        'description' => 'Extra metadata.',
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
        'additionalProperties' => [],
        'properties' => ['validation_overrides' => ['type' => 'string']],
    ])->and($diagnostics)->toBe([]);
});

/**
 * The path grammar end to end, one row per shape the name can take. A declaration either lands or is
 * refused by name — the row asserting the diagnostic code is what says "silently nothing" is not a third
 * outcome, and the schema beside it is what says the landing put the property where the name pointed.
 */
it('lands, or refuses by name, every shape a field path can take', function (array $seeded, string $name, array $expected, array $codes): void {
    $seed = $seeded === [] ? null : function (OperationDraft $operation) use ($seeded): void {
        $operation->set('requestBody', ['content' => ['application/json' => ['schema' => [
            'type' => 'object',
            'properties' => $seeded,
        ]]]], Contribution::integration('form-request'));
    };

    $diagnostics = [];
    $body = runBodyParameters([new BodyParameter(name: $name, type: 'string', description: 'D')], $seed, $diagnostics);

    expect($body['content']['application/json']['schema']['properties'])->toBe($expected)
        ->and(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe($codes);
})->with(function (): array {
    $declared = ['type' => 'string', 'description' => 'D'];

    return [
        'a plain name is a top-level property' => [
            [], 'nickname', ['nickname' => $declared], [],
        ],
        'one level descends into the property it names' => [
            [], 'meta.locale', ['meta' => ['type' => 'object', 'properties' => ['locale' => $declared]]], [],
        ],
        'a deep path builds every level it names' => [
            [], 'a.b.c.d',
            ['a' => ['type' => 'object', 'properties' => ['b' => ['type' => 'object', 'properties' => [
                'c' => ['type' => 'object', 'properties' => ['d' => $declared]],
            ]]]]],
            [],
        ],
        'an intermediate that does not exist is created beside the ones that do' => [
            ['meta' => ['type' => 'object', 'properties' => ['locale' => ['type' => 'string']]]],
            'meta.scoring.scores',
            ['meta' => ['type' => 'object', 'properties' => [
                'locale' => ['type' => 'string'],
                'scoring' => ['type' => 'object', 'properties' => ['scores' => $declared]],
            ]]],
            [],
        ],
        // Laravel's one word for both containers reaches the body as a union nobody decided. Naming a
        // key inside it decides it, at a layer that outranks the one that left it open.
        'an undecided container is settled by the key named inside it' => [
            ['meta' => ['type' => ['array', 'object']]],
            'meta.scoring',
            ['meta' => ['type' => 'object', 'properties' => ['scoring' => $declared]]],
            [],
        ],
        // …and only that question is settled: the server still takes a null, and a document saying
        // otherwise marks a working request invalid.
        'settling the container keeps the null the field admits' => [
            ['meta' => ['type' => ['array', 'object', 'null']]],
            'meta.scoring',
            ['meta' => ['type' => ['object', 'null'], 'properties' => ['scoring' => $declared]]],
            [],
        ],
        'a wildcard settles the container the other way and keeps the null too' => [
            ['lines' => ['type' => ['array', 'object', 'null']]],
            'lines.*.quantity',
            ['lines' => ['type' => ['array', 'null'], 'items' => ['type' => 'object', 'properties' => ['quantity' => $declared]]]],
            [],
        ],
        'a scalar parent is refused and left as it was' => [
            ['meta' => ['type' => 'string']],
            'meta.locale',
            ['meta' => ['type' => 'string']],
            ['attribute.body-parameter-parent'],
        ],
        'a composition parent is refused and left as it was' => [
            ['meta' => ['allOf' => [['type' => 'object']]]],
            'meta.locale',
            ['meta' => ['allOf' => [['type' => 'object']]]],
            ['attribute.body-parameter-parent'],
        ],
        'a $ref parent is refused, since every other use would inherit the property' => [
            ['meta' => ['$ref' => '#/components/schemas/Meta']],
            'meta.locale',
            ['meta' => ['$ref' => '#/components/schemas/Meta']],
            ['attribute.body-parameter-parent'],
        ],
        'an escaped dot names one field rather than descending' => [
            [], 'meta\.raw', ['meta.raw' => $declared], [],
        ],
        'a malformed path is refused by name' => [
            [], 'meta..raw', [], ['attribute.body-parameter-name'],
        ],
    ];
});

/**
 * The other half of settling the container: the field the rules left open stops being reported as open,
 * because the document no longer says "either". A note asking for rules that would say what a
 * declaration has already said fires exactly where nothing can be done.
 *
 * The rows below the first few are the other half of THAT: a declaration that names the field and
 * decides nothing about it, or names no field at all, leaves the question open — and standing the note
 * down for one of those would leave the reader wider than the rules left them, with nothing said.
 */
it('stops reporting a container as undecided only for a declaration that settles it', function (?BodyParameter $declared, array $reported): void {
    $rules = new RuleSet([
        'meta' => [ValidationRule::of('array')],
        'other' => [ValidationRule::of('array')],
    ]);

    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/things'),
        actionRef: new ActionRef('', null, 'store'),
        attributes: new AttributeSet($declared === null ? [] : [$declared]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions,
    );

    RuleSetNormalizer::report((new RuleSetNormalizer)->normalize($rules), $context, null);

    $fields = array_map(
        static fn (Diagnostic $d): string => (string) preg_replace('/^Validation field "([^"]+)".*$/', '$1', $d->message),
        $context->components->diagnostics(),
    );

    expect($fields)->toBe($reported);
})->with([
    'nothing declared leaves both open' => [null, ['meta', 'other']],
    'a key inside one settles that one' => [new BodyParameter(name: 'meta.scoring'), ['other']],
    'a key deep inside one settles it too' => [new BodyParameter(name: 'meta.scoring.scores'), ['other']],
    'a wildcard element settles it as a list' => [new BodyParameter(name: 'meta.*'), ['other']],
    // Naming the field says what the field IS, so what it says has to be read: with no `type` the
    // attribute's own default publishes a string, which is not "either" any more.
    'naming the field with no type at all settles it as the string it publishes' => [new BodyParameter(name: 'meta'), ['other']],
    'naming the field with a shape settles it' => [new BodyParameter(name: 'meta', type: 'list<string>'), ['other']],
    // The word for a free-form map: the answer for a field with no keys to enumerate, and the reason
    // the notice points at this attribute at all.
    'naming the field as an object settles it' => [new BodyParameter(name: 'meta', type: 'object'), ['other']],
    // …and the words that decide nothing. `array` is the very word the question is about, and a type
    // that resolves to no shape publishes the empty schema — wider than the "either" the note names,
    // with the note gone. The read is the write's own parser, so the two agree on what a shape is.
    'naming the field as an array settles nothing, being the word the question is about' => [new BodyParameter(name: 'meta', type: 'array'), ['meta', 'other']],
    'naming the field as mixed settles nothing either' => [new BodyParameter(name: 'meta', type: 'mixed'), ['meta', 'other']],
    // A path with an empty segment names no field, is reported as that mistake, and documents nothing
    // — so there is nothing for it to have settled.
    'a trailing dot names no field, so it settles nothing' => [new BodyParameter(name: 'meta.'), ['meta', 'other']],
    'a doubled dot names no field either' => [new BodyParameter(name: 'meta..scoring'), ['meta', 'other']],
    'a sibling field settles neither' => [new BodyParameter(name: 'unrelated.key'), ['meta', 'other']],
    // The escape is why this is a path comparison and not a string prefix: `meta\.scoring` is one
    // field whose own name holds a dot, and it says nothing about what `meta` is.
    'a field whose name holds a dot settles neither' => [new BodyParameter(name: 'meta\.scoring'), ['meta', 'other']],
]);

/**
 * `required` is the recovered body's, not the declaration's. The attribute's own `required` defaults to
 * `false` and an author cannot spell the difference between that default and a written `false`, so a
 * declaration that came to document a TYPE must not read as one that came to make a field optional —
 * a consumer's generated client would build requests the server rejects.
 */
it('leaves a recovered required list alone, at any depth, when a declaration says nothing about it', function (): void {
    $seed = function (OperationDraft $operation): void {
        $operation->set('requestBody', [
            'required' => true,
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'meta' => ['type' => 'object', 'properties' => [
                        'scoring' => [
                            'type' => 'object',
                            'properties' => ['scores' => ['type' => 'array'], 'other' => ['type' => 'string']],
                            'required' => ['scores', 'other'],
                        ],
                    ]],
                ],
                'required' => ['title'],
            ]]],
        ], Contribution::integration('form-request'));
    };

    $diagnostics = [];
    $body = runBodyParameters([
        new BodyParameter(name: 'title', type: 'int'),
        new BodyParameter(name: 'meta.scoring.scores', type: 'object'),
    ], $seed, $diagnostics);

    $schema = $body['content']['application/json']['schema'];
    $scoring = $schema['properties']['meta']['properties']['scoring'];

    // Both the field the declaration documented and the sibling beside it keep the requirement the
    // rules recovered, in the order they were recovered in.
    expect($scoring['required'])->toBe(['scores', 'other'])
        ->and($schema['required'])->toBe(['title'])
        // …and the declaration did land: this is the same write, not a write that stopped happening.
        ->and($scoring['properties']['scores'])->toBe(['type' => 'object', 'additionalProperties' => []])
        ->and($diagnostics)->toBe([]);
});

it('stands the note down only where a body declaration can reach the field', function (string $verb, array $reported): void {
    // `report()` runs ahead of the verb branch, and a read verb sends the recovered rules to QUERY
    // parameters instead of a body ({@see RecoveredRequest}). A #[BodyParameter] reaches a request body
    // and nothing else, so on a GET it settles nothing about the query parameter the rules produced —
    // and standing the notice down for it would leave that parameter wider than the rules left it with
    // nothing said, which is the exact case the consult exists to avoid.
    $rules = new RuleSet([
        'meta' => [ValidationRule::of('array')],
        'other' => [ValidationRule::of('array')],
    ]);

    $context = new RouteContext(
        route: new RouteDescriptor([strtoupper($verb)], 'api/things'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet([new BodyParameter(name: 'meta.scoring')]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions,
    );

    RuleSetNormalizer::report((new RuleSetNormalizer)->normalize($rules), $context, null);

    $fields = array_map(
        static fn (Diagnostic $d): string => (string) preg_replace('/^Validation field "([^"]+)".*$/', '$1', $d->message),
        $context->components->diagnostics(),
    );

    expect($fields)->toBe($reported);
})->with([
    // The verbs whose recovered rules become a body: the declaration reaches the field and settles it.
    'post' => ['post', ['other']],
    'put' => ['put', ['other']],
    'patch' => ['patch', ['other']],
    // …and the verbs whose rules become query parameters, where it reaches nothing.
    'get' => ['get', ['meta', 'other']],
    'head' => ['head', ['meta', 'other']],
]);

it('takes a written `required: false` off the list, at any depth, and leaves the siblings', function (): void {
    // The other half of "says nothing about it": the declaration now has a way to say `optional`, and a
    // declaration outranks the rules it patches. Widening is the direction that costs a consumer
    // nothing — a request the server accepts stays valid — where the narrow reading marks one invalid.
    $seed = function (OperationDraft $operation): void {
        $operation->set('requestBody', [
            'required' => true,
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'meta' => ['type' => 'object', 'properties' => [
                        'scoring' => [
                            'type' => 'object',
                            'properties' => ['scores' => ['type' => 'array'], 'other' => ['type' => 'string']],
                            'required' => ['scores', 'other'],
                        ],
                    ]],
                ],
                'required' => ['title', 'meta'],
            ]]],
        ], Contribution::integration('form-request'));
    };

    $diagnostics = [];
    $body = runBodyParameters([
        new BodyParameter(name: 'title', type: 'string', required: false),
        new BodyParameter(name: 'meta.scoring.scores', type: 'object', required: false),
    ], $seed, $diagnostics);

    $schema = $body['content']['application/json']['schema'];

    expect($schema['required'])->toBe(['meta'])
        ->and($schema['properties']['meta']['properties']['scoring']['required'])->toBe(['other'])
        // The body itself is still required — the rules said so, and one optional property is not a
        // statement about whether the request carries a body at all.
        ->and($body['required'])->toBeTrue()
        ->and($diagnostics)->toBe([]);
});

it('empties a required list a declaration takes the last name off', function (): void {
    // The keyword goes rather than being published as `[]`, which OAS forbids and a consumer's
    // generator reads as a schema with a required member it cannot name.
    $seed = function (OperationDraft $operation): void {
        $operation->set('requestBody', [
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['title' => ['type' => 'string']],
                'required' => ['title'],
            ]]],
        ], Contribution::integration('form-request'));
    };

    $body = runBodyParameters([new BodyParameter(name: 'title', required: false)], $seed);
    $schema = $body['content']['application/json']['schema'];

    expect($schema)->not->toHaveKey('required')
        ->and($body)->not->toHaveKey('required');
});
