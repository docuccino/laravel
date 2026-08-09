<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\ApiResources\JsonResourceSchema;
use Docuccino\Laravel\Integrations\Support\JsonApiDocument;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldJsonApiResourceSchema;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldResourceReflector;
use Docuccino\Laravel\Tests\Fixtures\TimacdonaldJsonApi\TimacdonaldArticleResource;
use Illuminate\Routing\Router;
use TiMacDonald\JsonApi\JsonApiResourceCollection;
use Workbench\App\Http\Controllers\FormController;

/**
 * The timacdonald/json-api integration (Phase 5c): the pre-13 JSON:API resource package Laravel 13's
 * first-party resources were upstreamed from. Its `to*()` surface is identical, so the shared
 * JSON:API document + params infra produces the same output behind a different class guard. The
 * document shape's self-reference cycle-break is proven once via the first-party ApiResources suite
 * (same {@see JsonApiDocument} builder).
 */
function timacdonaldEngine(): StubTypeEngine
{
    $loc = new SourceLocation('');
    $shape = static fn (array $fields): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT($fields), $loc)]);

    return new StubTypeEngine(analyses: [
        TimacdonaldArticleResource::class.'::toAttributes' => $shape([
            new ArrayShapeField('title', ScalarT::string()),
            new ArrayShapeField('body', ScalarT::string()),
        ]),
        // toLinks is NOT analysed — it returns Link objects, so the document builder special-cases it
        // from the fact that the resource overrides toLinks (see the links assertion below).
    ]);
}

it('maps a timacdonald JSON:API resource to a JSON:API document schema through the shared builder', function (): void {
    $components = new ComponentRegistry;
    $converter = new SchemaConverter(
        [new TimacdonaldJsonApiResourceSchema, new JsonResourceSchema, ...DefaultTypeMappers::all()],
        timacdonaldEngine(),
        $components,
        new RepresentationPolicy,
    );

    // The response root wraps the document envelope around a $ref to the hoisted resource object.
    $response = $converter->toSchema(new ClassT(TimacdonaldArticleResource::class))->schema;
    expect($response)->toBe([
        'type' => 'object',
        'properties' => ['data' => ['$ref' => '#/components/schemas/TimacdonaldArticleResource']],
        'required' => ['data'],
    ]);

    // The hoisted component is the resource object itself (no `{data: …}` envelope).
    $object = $components->schemas()['TimacdonaldArticleResource'];
    // `relationships` is intentionally absent: closure-valued relationships analyse as CallableT, so the
    // shared builder omits the member rather than emit a non-linkage shape (see JsonApiDocument docblock).
    expect($object['required'])->toBe(['id', 'type'])
        ->and(array_keys($object['properties']))->toBe(['id', 'type', 'attributes', 'links'])
        ->and($object['properties'])->not->toHaveKey('relationships')
        ->and($object['properties']['attributes']['properties'])->toHaveKeys(['title', 'body'])
        ->and($object['properties']['id'])->toBe(['type' => 'string'])
        // links is an object of relation-keyed link objects ({href, meta?}), emitted because the
        // resource overrides toLinks (the flat toArray analysis can't see the Link shape).
        ->and($object['properties']['links'])->toBe([
            'type' => 'object',
            'additionalProperties' => [
                'type' => 'object',
                'properties' => ['href' => ['type' => 'string'], 'meta' => ['type' => 'object']],
                'required' => ['href'],
            ],
        ]);
});

it('documents a timacdonald JSON:API collection as a single-wrapped array of resource objects', function (): void {
    $components = new ComponentRegistry;
    $converter = new SchemaConverter(
        [new TimacdonaldJsonApiResourceSchema, new JsonResourceSchema, ...DefaultTypeMappers::all()],
        timacdonaldEngine(),
        $components,
        new RepresentationPolicy,
    );

    $collection = new ClassT(JsonApiResourceCollection::class, [new ClassT(TimacdonaldArticleResource::class)]);
    $schema = $converter->toSchema($collection)->schema;

    // {data: [resource-object]} — each item is the bare object $ref, not a nested {data: {…}} document.
    expect($schema)->toBe([
        'type' => 'object',
        'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/TimacdonaldArticleResource']]],
        'required' => ['data'],
    ]);
});

it('declines a timacdonald resource in the plain JsonResource mapper (symmetric exclusion)', function (): void {
    // TimacdonaldArticleResource subclasses Illuminate's JsonResource, so without the symmetric
    // exclusion the plain mapper would claim it and emit a flat toArray shape (N5).
    expect((new JsonResourceSchema)->supports(new ClassT(TimacdonaldArticleResource::class)))->toBeFalse();
});

it('detects when a return type involves a timacdonald JSON:API document', function (): void {
    expect(TimacdonaldResourceReflector::involvesJsonApi(new ClassT(TimacdonaldArticleResource::class)))->toBeTrue()
        ->and(TimacdonaldResourceReflector::involvesJsonApi(new ClassT(JsonApiResourceCollection::class, [new ClassT(TimacdonaldArticleResource::class)])))->toBeTrue()
        ->and(TimacdonaldResourceReflector::involvesJsonApi(new ClassT('Illuminate\\Http\\Resources\\Json\\JsonResource')))->toBeFalse();
});

it('adds include + fields[type] params to an action returning a timacdonald resource', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/timacdonald-articles', [FormController::class, 'index']);

    app()->instance(TypeEngine::class, new StubTypeEngine(analyses: [
        'Workbench\\App\\Http\\Controllers\\FormController::index' => new ActionAnalysis(
            returns: [new ReturnSite(new ClassT(TimacdonaldArticleResource::class), new SourceLocation(''))],
        ),
    ]));

    $operation = generateDocument()->document->toArray()['paths']['/api/timacdonald-articles']['get'];

    $byName = paramsByName($operation);

    expect($byName)->toHaveKeys(['include', 'fields'])
        ->and($byName['fields']['style'])->toBe('deepObject')
        ->and($byName['include']['x-docuccino']['provenance'][0]['producer'])->toBe('integration:timacdonald-json-api');
});
