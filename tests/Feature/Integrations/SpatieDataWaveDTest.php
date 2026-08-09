<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Integrations\SpatieData\WrapResolver;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AuthorData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\PlainCasedData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\WrappedData;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * The remaining spatie/laravel-data surface: response wrapping (class `defaultWrap()` beats global
 * `config('data.wrap')` beats none, plus the paginated no-double-wrap), the
 * `include`/`exclude`/`only`/`except` request query-string partials, and the whole-class default
 * name-mapping strategy threaded into input and output keys. The static `rules()` override's recovery
 * half is proven against the real engine in RealEngineIntegrationsTest.
 */
function waveDEngine(): StubTypeEngine
{
    return new StubTypeEngine(classes: [
        WrappedData::class => new ClassMetadata(WrappedData::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('name', ScalarT::string()),
        ]),
        AuthorData::class => new ClassMetadata(AuthorData::class, [
            new PropertyMetadata('name', ScalarT::string()),
            new PropertyMetadata('email', ScalarT::string()),
        ]),
    ]);
}

/**
 * Convert a type at the response root (depth 1) through a DataSchema carrying the given wrap resolver.
 */
function convertWithWrap(ClassT $type, WrapResolver $wrap): array
{
    $components = new ComponentRegistry;
    $converter = new SchemaConverter([new DataSchema(wrap: $wrap), ...DefaultTypeMappers::all()], waveDEngine(), $components);

    return ['root' => $converter->toSchema($type)->schema, 'components' => $components];
}

// Wrapping — precedence + non-double-wrap.

it('wraps a top-level Data object under the class defaultWrap() key', function (): void {
    // defaultWrap() → 'record' wins even with a global 'data' configured (WrapType::Defined).
    $result = convertWithWrap(new ClassT(WrappedData::class), new WrapResolver('data'));

    expect($result['root'])->toBe([
        'type' => 'object',
        'properties' => ['record' => ['$ref' => '#/components/schemas/Wrapped']],
        'required' => ['record'],
    ]);
    // The hoisted component itself stays unwrapped so a shared/nested $ref never carries the envelope.
    expect(array_keys($result['components']->schemas()['Wrapped']['properties']))->toBe(['id', 'name']);
});

it('wraps a top-level Data object under the global config key when there is no defaultWrap()', function (): void {
    $result = convertWithWrap(new ClassT(AuthorData::class), new WrapResolver('data'));

    expect($result['root'])->toBe([
        'type' => 'object',
        'properties' => ['data' => ['$ref' => '#/components/schemas/AuthorData']],
        'required' => ['data'],
    ]);
});

it('leaves a top-level Data object unwrapped when neither defaultWrap() nor global config is set', function (): void {
    $result = convertWithWrap(new ClassT(AuthorData::class), new WrapResolver);

    expect($result['root'])->toBe(['$ref' => '#/components/schemas/AuthorData']);
});

it('does not wrap a nested Data property (only the response root is wrapped)', function (): void {
    // With a global wrap the root is wrapped, but AuthorData appearing as a nested property inside
    // another component isn't — its component body stays flat.
    $result = convertWithWrap(new ClassT(AuthorData::class), new WrapResolver('data'));

    // The AuthorData component (a would-be nested shape) has no wrap key baked into its body.
    expect($result['components']->schemas()['AuthorData']['properties'])->toHaveKeys(['name', 'email'])
        ->and($result['components']->schemas()['AuthorData']['properties'])->not->toHaveKey('data');
});

it('uses the wrap key as the paginated envelope items key without double-wrapping', function (string $wrap, string $expectedItemsKey): void {
    $result = convertWithWrap(
        new ClassT('Spatie\\LaravelData\\PaginatedDataCollection', [ScalarT::int(), new ClassT(AuthorData::class)]),
        new WrapResolver($wrap),
    );

    // The envelope is {itemsKey, links, meta}: the wrap key becomes the items key rather than an outer
    // wrap around the whole {data,links,meta} shape (spatie's PaginatedCollectionIsAlwaysWrapped).
    expect(array_keys($result['root']['properties']))->toBe([$expectedItemsKey, 'links', 'meta'])
        ->and($result['root']['required'])->toBe([$expectedItemsKey, 'links', 'meta'])
        ->and($result['root']['properties'][$expectedItemsKey]['type'])->toBe('array')
        ->and($result['root']['properties'][$expectedItemsKey]['items'])->toHaveKey('$ref');
})->with([
    'default data key' => ['data', 'data'],
    'custom global key' => ['records', 'records'],
]);

it('defaults the paginated envelope items key to data when no wrap is configured', function (): void {
    $result = convertWithWrap(
        new ClassT('Spatie\\LaravelData\\CursorPaginatedDataCollection', [ScalarT::int(), new ClassT(AuthorData::class)]),
        new WrapResolver,
    );

    expect(array_keys($result['root']['properties']))->toBe(['data', 'links', 'meta']);
});

it('wraps a plain DataCollection at the root under the global key, but leaves it a bare array nested', function (): void {
    $wrapped = convertWithWrap(new ClassT(DataClassReflector::DATA_COLLECTION, [ScalarT::int(), new ClassT(AuthorData::class)]), new WrapResolver('data'));

    expect($wrapped['root'])->toBe([
        'type' => 'object',
        'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/AuthorData']]],
        'required' => ['data'],
    ]);

    // With no wrap configured the same collection is a bare array (no envelope).
    $bare = convertWithWrap(new ClassT(DataClassReflector::DATA_COLLECTION, [ScalarT::int(), new ClassT(AuthorData::class)]), new WrapResolver);
    expect($bare['root']['type'])->toBe('array');
});

it('reads a literal defaultWrap() override off the class, and null for a class without one', function (): void {
    expect((new WrapResolver)->key(WrappedData::class))->toBe('record')
        ->and((new WrapResolver('data'))->key(AuthorData::class))->toBe('data')
        ->and((new WrapResolver)->key(AuthorData::class))->toBeNull()
        ->and((new WrapResolver)->key(null))->toBeNull();
});

// include / exclude / only / except request partials.

it('detects only the overridden allowedRequest*() partials on a Data class', function (): void {
    // WrappedData overrides allowedRequestIncludes() + allowedRequestOnly(); exclude/except are the inert
    // base methods, so they aren't documented.
    expect((new DataClassReflector)->requestPartials(WrappedData::class))->toBe(['include', 'only'])
        ->and((new DataClassReflector)->requestPartials(AuthorData::class))->toBe([]);
});

it('adds the overridden partials as query params on an action returning the Data class', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/wrapped', [FormController::class, 'index']);

    app()->instance(TypeEngine::class, new StubTypeEngine(analyses: [
        'Workbench\\App\\Http\\Controllers\\FormController::index' => new ActionAnalysis(
            returns: [new ReturnSite(new ClassT(WrappedData::class), new SourceLocation(''))],
        ),
    ]));

    $operation = generateDocument()->document->toArray()['paths']['/api/wrapped']['get'];
    $byName = paramsByName($operation);

    // include + only are documented as optional comma-list string query params; exclude/except are not.
    expect(array_keys($byName))->toContain('include', 'only')
        ->and($byName)->not->toHaveKeys(['exclude', 'except'])
        ->and($byName['include']['in'])->toBe('query')
        ->and($byName['include']['required'] ?? false)->toBeFalse()
        ->and($byName['include']['schema']['type'])->toBe('string')
        ->and($byName['only']['x-docuccino']['provenance'][0]['producer'])->toBe('integration:spatie-data');
});

// Global name-mapping strategy threaded into input + output keys.

it('applies the global name-mapping strategy to every unmapped key (input + output)', function (?string $mapper, string $expected): void {
    $reflector = new DataClassReflector(globalInputMapper: $mapper, globalOutputMapper: $mapper);

    // displayName has no map attribute, so the global strategy governs it in both directions.
    expect($reflector->inputName(PlainCasedData::class, 'displayName'))->toBe($expected)
        ->and($reflector->outputName(PlainCasedData::class, 'displayName'))->toBe($expected);
})->with([
    'snake' => ['Spatie\\LaravelData\\Mappers\\SnakeCaseMapper', 'display_name'],
    'camel' => ['Spatie\\LaravelData\\Mappers\\CamelCaseMapper', 'displayName'],
    'studly' => ['Spatie\\LaravelData\\Mappers\\StudlyCaseMapper', 'DisplayName'],
    'lower' => ['Spatie\\LaravelData\\Mappers\\LowerCaseMapper', 'displayname'],
    'upper' => ['Spatie\\LaravelData\\Mappers\\UpperCaseMapper', 'DISPLAYNAME'],
]);

it('lets an explicit #[MapName] win over the global strategy, and degrades an unknown global mapper', function (): void {
    $snake = new DataClassReflector(globalInputMapper: 'Spatie\\LaravelData\\Mappers\\SnakeCaseMapper', globalOutputMapper: 'Spatie\\LaravelData\\Mappers\\SnakeCaseMapper');

    // userName carries #[MapName('handle')], so the attribute wins and the global default is ignored.
    expect($snake->inputName(PlainCasedData::class, 'userName'))->toBe('handle')
        ->and($snake->outputName(PlainCasedData::class, 'userName'))->toBe('handle');

    // An unrecognised global mapper class degrades to the property name (never leaks the FQCN).
    $unknown = new DataClassReflector(globalInputMapper: 'App\\Mappers\\Custom', globalOutputMapper: 'App\\Mappers\\Custom');
    expect($unknown->inputName(PlainCasedData::class, 'displayName'))->toBe('displayName');

    // With no global strategy the property name is used verbatim.
    expect((new DataClassReflector)->outputName(PlainCasedData::class, 'displayName'))->toBe('displayName');
});

it('applies distinct input and output global strategies independently', function (): void {
    $reflector = new DataClassReflector(globalInputMapper: 'Spatie\\LaravelData\\Mappers\\SnakeCaseMapper', globalOutputMapper: 'Spatie\\LaravelData\\Mappers\\StudlyCaseMapper');

    expect($reflector->inputName(PlainCasedData::class, 'displayName'))->toBe('display_name')
        ->and($reflector->outputName(PlainCasedData::class, 'displayName'))->toBe('DisplayName');
});
