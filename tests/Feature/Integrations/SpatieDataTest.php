<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AuthorData;

/**
 * The spatie/laravel-data integration: a Data class becomes a hoisted component honouring the reflected
 * presentation facts (`#[Hidden]`, `#[MapName]`, `Optional`, `#[SchemaName]`/`#[SchemaId]`, nested
 * recursion), its collection variants become array / paginator envelopes, and the request side recovers
 * rules for the shared validation chain.
 */
function spatieDataEngine(): StubTypeEngine
{
    return new StubTypeEngine(classes: [
        ArticleData::class => new ClassMetadata(ArticleData::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('title', ScalarT::string()),
            new PropertyMetadata('body', ScalarT::string()),
            new PropertyMetadata('secret', ScalarT::string()),
            new PropertyMetadata('internal', ScalarT::int()),
            // The Optional marker leaks into the type; the mapper strips it and marks the prop optional.
            new PropertyMetadata('subtitle', UnionT::of([ScalarT::string(), new ClassT(DataClassReflector::OPTIONAL)])),
            new PropertyMetadata('author', UnionT::of([new ClassT(AuthorData::class), new NullT])),
        ]),
        AuthorData::class => new ClassMetadata(AuthorData::class, [
            new PropertyMetadata('name', ScalarT::string()),
            new PropertyMetadata('email', ScalarT::string()),
        ]),
    ]);
}

function convertData(ClassT $type): ComponentRegistry
{
    $components = new ComponentRegistry;
    $converter = new SchemaConverter([new DataSchema, ...DefaultTypeMappers::all()], spatieDataEngine(), $components);
    $converter->toSchema($type);

    return $components;
}

it('hoists a Data class to a component honouring #[SchemaName]/#[SchemaId], hidden, and mapping', function (): void {
    $components = convertData(new ClassT(ArticleData::class));

    $schemas = $components->schemas();
    // #[SchemaName('Article')] names the component; the nested Data hoists under its own name.
    expect($schemas)->toHaveKeys(['Article', 'AuthorData']);
    // #[SchemaId('article.v1')] pins the component identity.
    expect($components->schemaIds()['Article'] ?? null)->toBe('article.v1');

    $article = $schemas['Article'];
    // secret (spatie #[Hidden]) and internal (class-level Docuccino #[Hidden]) are dropped; title is
    // renamed to its #[MapName] output key.
    expect(array_keys($article['properties']))->toBe(['id', 'headline', 'body', 'subtitle', 'author']);
    // Only the Optional-marked subtitle is non-required (marker stripped). A nullable-but-always-emitted
    // property (author) stays required under the cross-mapper required-vs-nullable convention: the key is
    // on the wire, carrying null.
    expect($article['required'])->toBe(['id', 'headline', 'body', 'author'])
        ->and($article['properties']['subtitle'])->toBe(['type' => 'string'])
        // author is nullable AuthorData → an anyOf referencing the hoisted component + null.
        ->and($article['properties']['author']['anyOf'][0]['$ref'] ?? null)->toBe('#/components/schemas/AuthorData');
});

it('renders a paginated DataCollection as spatie\'s own length-aware envelope', function (): void {
    $converter = new SchemaConverter([new DataSchema, ...DefaultTypeMappers::all()], spatieDataEngine(), new ComponentRegistry);

    // The two-arg shape spatie's own generics (`@template TKey of array-key, @template TValue`) and the
    // docblock parser produce: [TKey=int, TValue=AuthorData]. The item type is the *last* arg — reading
    // typeArgs[0] would document the items as `{type: integer}`, i.e. the key.
    $paginated = $converter->toSchema(new ClassT('Spatie\\LaravelData\\PaginatedDataCollection', [ScalarT::int(), new ClassT(AuthorData::class)]))->schema;
    // Spatie's own shape, not the Laravel resource envelope: data/links/meta all required, `links` an
    // array of {url,label,active} objects, `meta` carrying the *_page_url members.
    expect($paginated['type'])->toBe('object')
        ->and($paginated['required'])->toBe(['data', 'links', 'meta'])
        ->and($paginated['properties']['data']['type'])->toBe('array')
        ->and($paginated['properties']['data']['items'])->toHaveKey('$ref')
        ->and($paginated['properties']['links']['type'])->toBe('array')
        ->and($paginated['properties']['links']['items']['properties'])->toHaveKeys(['url', 'label', 'active'])
        ->and($paginated['properties']['meta']['properties'])->toHaveKeys(['total', 'first_page_url', 'last_page_url', 'next_page_url', 'prev_page_url']);

    $simple = $converter->toSchema(new ClassT(DataClassReflector::DATA_COLLECTION, [ScalarT::int(), new ClassT(AuthorData::class)]))->schema;
    expect($simple['type'])->toBe('array')
        ->and($simple['items'])->toHaveKey('$ref');
});

it('reads the collection ITEM type from the last generic arg across arities', function (array $typeArgs, bool $expectRef): void {
    $converter = new SchemaConverter([new DataSchema, ...DefaultTypeMappers::all()], spatieDataEngine(), new ComponentRegistry);

    $simple = $converter->toSchema(new ClassT(DataClassReflector::DATA_COLLECTION, $typeArgs))->schema;

    expect($simple['type'])->toBe('array');
    if ($expectRef) {
        // The value type resolves to the hoisted AuthorData component, never the {type:integer} key.
        expect($simple['items'])->toHaveKey('$ref')
            ->and($simple['items'])->not->toBe(['type' => 'integer']);
    } else {
        // Bare `DataCollection` (no generics) gives an untyped item array.
        expect($simple['items'])->toBe([]);
    }
})->with([
    'bare (no generics)' => [[], false],
    'one-arg <AuthorData>' => [[new ClassT(AuthorData::class)], true],
    'two-arg <int, AuthorData>' => [[ScalarT::int(), new ClassT(AuthorData::class)], true],
]);

it('renders a cursor-paginated DataCollection as spatie\'s cursor envelope', function (): void {
    $converter = new SchemaConverter([new DataSchema, ...DefaultTypeMappers::all()], spatieDataEngine(), new ComponentRegistry);

    $cursor = $converter->toSchema(new ClassT('Spatie\\LaravelData\\CursorPaginatedDataCollection', [ScalarT::int(), new ClassT(AuthorData::class)]))->schema;
    // Cursor: empty `links` array, cursor tokens + neighbouring page URLs in meta, no total/last_page.
    expect($cursor['required'])->toBe(['data', 'links', 'meta'])
        ->and($cursor['properties']['links']['type'])->toBe('array')
        ->and($cursor['properties']['meta']['properties'])->toHaveKeys(['next_cursor', 'prev_cursor', 'next_page_url', 'prev_page_url'])
        ->and($cursor['properties']['meta']['properties'])->not->toHaveKey('total');
});

it('recovers request rules from Data properties + spatie validation attributes', function (): void {
    $engine = spatieDataEngine();
    $metadata = $engine->classMetadata(new ClassRef(ArticleData::class));
    $ruleSet = (new DataValidationRules)->build(ArticleData::class, $metadata, $engine);

    $names = static fn (string $field): array => array_map(
        static fn ($rule): string => $rule->name,
        $ruleSet->fields[$field] ?? [],
    );

    // #[MapName] input key ('heading') is used for the request; required + type synthesised.
    expect($ruleSet->fields)->toHaveKey('heading');
    expect($names('heading'))->toBe(['required', 'string']);
    // #[Max(500)] → 'max:500' fed through the shared chain, alongside synthesised presence/type.
    expect($names('body'))->toContain('required')->toContain('string')->toContain('max');
    $bodyMax = collect($ruleSet->fields['body'])->firstWhere('name', 'max');
    expect($bodyMax?->parameter(0))->toBe('500');
    // Optional marker → 'sometimes' instead of 'required'.
    expect($names('subtitle'))->toContain('sometimes')->not->toContain('required');
});

it('reflects presentation facts off the real Data class', function (): void {
    $reflector = new DataClassReflector;

    expect(DataClassReflector::isData(ArticleData::class))->toBeTrue()
        ->and($reflector->isPropertyHidden(ArticleData::class, 'secret'))->toBeTrue()
        ->and($reflector->isPropertyOptional(ArticleData::class, 'subtitle'))->toBeTrue()
        ->and($reflector->outputName(ArticleData::class, 'title'))->toBe('headline')
        ->and($reflector->inputName(ArticleData::class, 'title'))->toBe('heading')
        ->and($reflector->validationTokens(ArticleData::class, 'body'))->toBe(['max:500'])
        ->and($reflector->collectionKind('Spatie\\LaravelData\\PaginatedDataCollection'))->toBe('length');
});
