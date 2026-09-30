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
use Docuccino\Laravel\Tests\Fixtures\TimacdonaldJsonApi\FlatTimacdonaldResource;
use Docuccino\Laravel\Tests\Fixtures\TimacdonaldJsonApi\TimacdonaldArticleResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Router;
use TiMacDonald\JsonApi\JsonApiResourceCollection;
use Workbench\App\Http\Controllers\FormController;

/**
 * The timacdonald/json-api integration: the JSON:API resource package Laravel's first-party resources
 * (12.45 and later) were upstreamed from. Its `to*()` surface is identical, so the shared JSON:API document +
 * params infra produces the same output behind a different class guard. The self-reference cycle-break is
 * proven once in the first-party ApiResources suite — same {@see JsonApiDocument} builder.
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
        // toLinks isn't analysed: it returns Link objects, so the builder keys off the resource simply
        // overriding toLinks (see the links assertion below).
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

    // The response root wraps the document envelope around a $ref to the hoisted resource object — on a
    // release that sends one. An older release under Laravel 12.45 or later sends a resource, and each one
    // it includes, as its attributes alone, so the document claims only an object and says why.
    $sends = timacdonaldSendsResourceObjects();
    $response = $converter->toSchema(new ClassT(TimacdonaldArticleResource::class))->schema;
    expect($response)->toBe([
        'type' => 'object',
        'properties' => [
            'data' => $sends ? ['$ref' => '#/components/schemas/TimacdonaldArticleResource'] : ['type' => 'object'],
            'included' => ['description' => 'Resource objects related to the primary data, sent as a compound document.', 'type' => 'array', 'items' => $sends ? ['$ref' => '#/components/schemas/JsonApiResourceObject'] : ['type' => 'object']],
        ],
        'required' => ['data'],
    ])->and(array_map(static fn ($d): string => $d->code, $components->diagnostics()))->toBe($sends ? [] : ['timacdonald-json-api.resource-object-not-sent']);

    if (! $sends) {
        expect($components->schemas())->not->toHaveKey('TimacdonaldArticleResource')
            ->and($components->diagnostics()[0]->message)->toContain(TimacdonaldArticleResource::class.' is sent as its attributes alone');

        return;
    }

    // The hoisted component is the resource object itself (no `{data: …}` envelope).
    $object = $components->schemas()['TimacdonaldArticleResource'];
    // `relationships` is absent by design: closure-valued relationships analyse as CallableT, so the shared
    // builder omits the member rather than emit a non-linkage shape (see JsonApiDocument).
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

    // {data: [resource-object]} — each item is the bare object $ref, not a nested {data: {…}} document;
    // an open object where the installed release sends each item as its attributes.
    $sends = timacdonaldSendsResourceObjects();
    expect($schema)->toBe([
        'type' => 'object',
        'properties' => [
            'data' => ['type' => 'array', 'items' => $sends ? ['$ref' => '#/components/schemas/TimacdonaldArticleResource'] : ['type' => 'object']],
            'included' => ['description' => 'Resource objects related to the primary data, sent as a compound document.', 'type' => 'array', 'items' => $sends ? ['$ref' => '#/components/schemas/JsonApiResourceObject'] : ['type' => 'object']],
        ],
        'required' => ['data'],
    ]);
});

it('publishes a timacdonald resource sending its own resolveResourceData() as an open object, on every release', function (): void {
    $components = new ComponentRegistry;
    $converter = new SchemaConverter(
        [new TimacdonaldJsonApiResourceSchema, new JsonResourceSchema, ...DefaultTypeMappers::all()],
        timacdonaldEngine(),
        $components,
        new RepresentationPolicy,
    );
    $schema = $converter->toSchema(new ClassT(FlatTimacdonaldResource::class))->schema;

    // Laravel 12.45 and later send what the override returns — here no `type`, so no resource object.
    $model = new class extends Model {};
    $model->forceFill(['id' => 1, 'title' => 't']);
    $sent = json_decode((string) (new FlatTimacdonaldResource($model))->toResponse(Request::create('/'))->getContent());
    $diagnostics = $components->diagnostics();

    expect($sent->data)->toEqual((object) ['id' => '1', 'title' => 't'])
        ->and($schema['properties']['data'])->toBe(['type' => 'object'])
        ->and(array_map(static fn ($d): string => $d->code, $diagnostics))->toBe(['timacdonald-json-api.resource-object-not-sent'])
        ->and($diagnostics[0]->message)->toContain('resolveResourceData() declared on '.FlatTimacdonaldResource::class);
});

it('reads which class sends a timacdonald resource from the installed code', function (): void {
    // The package's own resolveResourceData() sends the resource object; Laravel's base sends toAttributes().
    expect(TimacdonaldResourceReflector::sentOtherwise(TimacdonaldArticleResource::class))->toBe(timacdonaldSendsResourceObjects() ? null : JsonResource::class)
        ->and(TimacdonaldResourceReflector::sentOtherwise(FlatTimacdonaldResource::class))->toBe(FlatTimacdonaldResource::class)
        ->and(TimacdonaldResourceReflector::sentOtherwise('App\\Missing'))->toBeNull();

    // Each sender names its own remedy, whichever release is installed.
    $base = TimacdonaldResourceReflector::notSent('App\\Article', JsonResource::class);
    $override = TimacdonaldResourceReflector::notSent('App\\Article', 'App\\Base');

    expect($base->code)->toBe('timacdonald-json-api.resource-object-not-sent')
        ->and($base->message)->toContain('App\\Article is sent as its attributes alone')
        ->and($base->help)->toContain('v1.0.0-beta.10')
        ->and($override->code)->toBe('timacdonald-json-api.resource-object-not-sent')
        ->and($override->message)->toContain('resolveResourceData() declared on App\\Base')
        ->and($override->help)->toContain('Remove the override');
});

it('declines a timacdonald resource in the plain JsonResource mapper (symmetric exclusion)', function (): void {
    // TimacdonaldArticleResource subclasses Illuminate's JsonResource, so without the symmetric exclusion
    // the plain mapper would claim it and emit a flat toArray shape.
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
