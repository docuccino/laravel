<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\ApiResources\JsonApiResourceSchema;
use Docuccino\Laravel\Integrations\ApiResources\JsonResourceSchema;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Integrations\SpatieData\WrapResolver;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleJsonApiResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\AuthorResource;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AuthorData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ProblemDocumentData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\TagData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\WrappedData;

/**
 * A response-root envelope survives a composite sitting at the root. Three producers mint one — Laravel's
 * resource `data` wrap, the JSON:API document, spatie's `data.wrap` — and all three ask the same question
 * of the conversion, so the invariant is stated here once for the set rather than three times over.
 *
 * A union branch stands exactly where its union stood, so the envelope belongs on each ARM: that is what
 * the server sends (whichever arm is returned is wrapped by its own class's key), and it is the only shape
 * that stays true when the arms disagree — one key against another, or a wrapped arm beside one that
 * strips the envelope itself.
 */
function rootEnvelopeEngine(): StubTypeEngine
{
    $loc = new SourceLocation('');
    $shape = static fn (array $fields): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT($fields), $loc)]);

    return new StubTypeEngine(
        analyses: [
            ArticleResource::class.'::toArray' => $shape([new ArrayShapeField('title', ScalarT::string())]),
            AuthorResource::class.'::toArray' => $shape([new ArrayShapeField('name', ScalarT::string())]),
            ArticleJsonApiResource::class.'::toAttributes' => $shape([new ArrayShapeField('title', ScalarT::string())]),
        ],
        classes: [
            AuthorData::class => new ClassMetadata(AuthorData::class, [new PropertyMetadata('name', ScalarT::string())]),
            WrappedData::class => new ClassMetadata(WrappedData::class, [new PropertyMetadata('id', ScalarT::int())]),
            TagData::class => new ClassMetadata(TagData::class, [new PropertyMetadata('label', ScalarT::string())]),
            ProblemDocumentData::class => new ClassMetadata(ProblemDocumentData::class, [new PropertyMetadata('type', ScalarT::string())]),
        ],
    );
}

/** The root schema for `$type`, through every envelope-minting mapper at once. */
function rootSchema(DType $type, string $nullable = 'type-array'): array
{
    return (new SchemaConverter(
        [new JsonApiResourceSchema, new JsonResourceSchema, new DataSchema(wrap: new WrapResolver('data')), ...DefaultTypeMappers::all()],
        rootEnvelopeEngine(),
        new ComponentRegistry,
        new RepresentationPolicy(nullable: $nullable),
    ))->toSchema($type)->schema;
}

/** `{"<key>": {"$ref": …}}`, the envelope every one of these producers emits. */
function wrapped(string $key, string $component): array
{
    return [
        'type' => 'object',
        'properties' => [$key => ['$ref' => '#/components/schemas/'.$component]],
        'required' => [$key],
    ];
}

it('keeps the root envelope on every arm of a composite at the root', function (DType $type, array $expected): void {
    expect(rootSchema($type))->toBe($expected);
})->with([
    // Laravel resource `data` wrap. Before this the anyOf published the bare resource, so a generated
    // client read `response.title` off a body that really sends `{"data": {"title": …}}`.
    'nullable resource' => [
        UnionT::of([new ClassT(ArticleResource::class), new NullT]),
        ['type' => ['object', 'null'], 'properties' => ['data' => ['$ref' => '#/components/schemas/ArticleResource']], 'required' => ['data']],
    ],
    'two resources' => [
        UnionT::of([new ClassT(ArticleResource::class), new ClassT(AuthorResource::class)]),
        ['anyOf' => [wrapped('data', 'ArticleResource'), wrapped('data', 'AuthorResource')]],
    ],

    // The JSON:API document root.
    'nullable json:api resource' => [
        UnionT::of([new ClassT(ArticleJsonApiResource::class), new NullT]),
        ['type' => ['object', 'null'], 'properties' => ['data' => ['$ref' => '#/components/schemas/ArticleJsonApiResource']], 'required' => ['data']],
    ],

    // spatie's `data.wrap`.
    'nullable Data class' => [
        UnionT::of([new ClassT(AuthorData::class), new NullT]),
        ['type' => ['object', 'null'], 'properties' => ['data' => ['$ref' => '#/components/schemas/AuthorData']], 'required' => ['data']],
    ],
    // Per-arm is not a detail: these two arms disagree on the key, so no single outer envelope is true.
    'Data classes whose wrap keys differ' => [
        UnionT::of([new ClassT(AuthorData::class), new ClassT(WrappedData::class)]),
        ['anyOf' => [wrapped('data', 'AuthorData'), wrapped('record', 'Wrapped')]],
    ],
    // The shape that surfaced this: a success arm beside a problem document, which sits at the root by
    // definition and strips the envelope itself.
    'a wrapped arm beside a self-unwrapping one' => [
        UnionT::of([new ClassT(AuthorData::class), new ClassT(ProblemDocumentData::class)]),
        ['anyOf' => [wrapped('data', 'AuthorData'), ['$ref' => '#/components/schemas/ProblemDocumentData']]],
    ],
    // An intersection composes at one position too, and its arms agree on the key: `data` satisfies both.
    'intersection of two Data classes' => [
        new IntersectionT([new ClassT(AuthorData::class), new ClassT(TagData::class)]),
        ['allOf' => [wrapped('data', 'AuthorData'), wrapped('data', 'TagData')]],
    ],
]);

it('leaves a composite that is not at the root unwrapped', function (): void {
    // The negative half, and the reason root-ness is a POSITION rather than a recursion count: an array's
    // items are somewhere else, so wrapping the arms there would bury an envelope inside a list.
    $schema = rootSchema(new ListT(UnionT::of([new ClassT(ArticleResource::class), new ClassT(AuthorResource::class)])));

    expect($schema)->toBe([
        'type' => 'array',
        'items' => ['anyOf' => [
            ['$ref' => '#/components/schemas/ArticleResource'],
            ['$ref' => '#/components/schemas/AuthorResource'],
        ]],
    ]);
});

it('keeps the envelope under either spelling of a nullable root', function (): void {
    // `type-array` folds the null into the envelope's own type; `anyof` gives it a branch. The envelope has
    // to survive both, since the policy is the document's choice and says nothing about wrapping.
    $type = UnionT::of([new ClassT(AuthorData::class), new NullT]);

    expect(rootSchema($type, 'anyof'))
        ->toBe(['anyOf' => [wrapped('data', 'AuthorData'), ['type' => 'null']]]);
});

it('publishes the per-arm envelope through the whole pipeline', function (): void {
    // The converter is only half the path: this pins what the emitter actually writes into the
    // operation, since the response body arrives at the draft as loose keywords rather than one schema.
    $loc = new SourceLocation('');
    $shape = static fn (array $fields): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT($fields), $loc)]);

    app()->instance(TypeEngine::class, new StubTypeEngine(analyses: [
        'Workbench\\App\\Http\\Controllers\\FormController::index' => new ActionAnalysis(returns: [new ReturnSite(
            UnionT::of([new ClassT(ArticleResource::class), new ClassT(AuthorResource::class)]),
            $loc,
        )]),
        ArticleResource::class.'::toArray' => $shape([
            new ArrayShapeField('id', ScalarT::int()),
            new ArrayShapeField('title', ScalarT::string()),
        ]),
        AuthorResource::class.'::toArray' => $shape([
            new ArrayShapeField('id', ScalarT::int()),
            new ArrayShapeField('name', ScalarT::string()),
        ]),
    ]));

    $document = generateDocument()->document->toArray();
    $body = stripDocuccino($document['paths']['/api/forms']['get']['responses']['200']['content']['application/json']['schema']);

    expect($body)->toBe(['anyOf' => [
        wrapped('data', 'ArticleResource'),
        wrapped('data', 'AuthorResource'),
    ]]);
});
