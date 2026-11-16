<?php

declare(strict_types=1);

use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\QueryBuilder\QbEntry;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderConfig;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderFacts;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParameters;
use Docuccino\Laravel\Integrations\Support\JsonApiParameters;

/*
 * A sort, include or fields list states what its allow-list settles: the values, that `-` sorts
 * descending, the separator, and a default the schema could not carry. Under a separator other than the
 * comma the list is a plain string, so its description is the only place a reader learns any of it; under
 * the comma the values are an enum too, but what `-` means and an unlisted default are still prose. All of
 * it is true whatever prose describes the parameter, so an author's `#[QueryParameter(description:)]`
 * leads and the allow-list's sentences stand beneath it. Undescribed, the list reads as it always has.
 */
function allowListParameters(string $delimiter, string $style, array $attributes): array
{
    $facts = new QueryBuilderFacts;
    $facts->sorts = [new QbEntry('name', 'default'), new QbEntry('created_at', 'default')];
    // Not allow-listed, so under the comma it cannot ride on the enum's schema either.
    $facts->defaultSorts = ['-id'];
    $facts->includes = [new QbEntry('posts', 'default')];
    $facts->fields = [new QbEntry('id', 'default'), new QbEntry('posts.title', 'default')];

    return attributeParameters($attributes, static function (OperationDraft $operation) use ($facts, $delimiter, $style): void {
        foreach ((new QueryBuilderParameters)->build($facts, new RepresentationPolicy(filterStyle: $style), new QueryBuilderConfig(delimiter: $delimiter)) as $spec) {
            $spec->applyTo($operation->parameter('query', $spec->name), Contribution::integration('query-builder'));
        }
    });
}

it('keeps what an allow-list settles beneath the description its author writes', function (string $delimiter, string $parameter, string $facts): void {
    $described = allowListParameters($delimiter, 'bracketed', [new QueryParameter($parameter, description: 'Written by the author.')]);
    $undescribed = allowListParameters($delimiter, 'bracketed', []);

    expect($described[$parameter]['description'])->toBe("Written by the author.\n\n".$facts)
        ->and($undescribed[$parameter]['description'])->toBe($facts);
})->with([
    'sort, another separator' => ['|', 'sort', 'Sort by: name, created_at (prefix `-` for descending). Values are separated by `|`. Defaults to `-id`.'],
    'include, another separator' => ['|', 'include', 'Include related resources: posts. Values are separated by `|`.'],
    'fields, another separator' => ['|', 'fields', 'Fields to return: id. Values are separated by `|`.'],
    'a type\'s fields, another separator' => ['|', 'fields[posts]', 'Fields to return: title. Values are separated by `|`.'],
    'sort, the comma' => [',', 'sort', 'Sort by: name, created_at (prefix `-` for descending). Defaults to `-id`.'],
    'include, the comma' => [',', 'include', 'Include related resources: posts.'],
    'fields, the comma' => [',', 'fields', 'Fields to return: id.'],
]);

it('keeps what a type\'s fieldset settles beneath its author\'s description in the deepObject representation', function (string $delimiter, string $facts): void {
    $described = allowListParameters($delimiter, 'deepObject', [new QueryParameter('fields[posts]', description: 'Written by the author.')]);
    $undescribed = allowListParameters($delimiter, 'deepObject', []);

    expect($described['fields']['schema']['properties']['posts']['description'])->toBe("Written by the author.\n\n".$facts)
        ->and($undescribed['fields']['schema']['properties']['posts']['description'])->toBe($facts);
})->with([
    'another separator' => ['|', 'Fields to return: title. Values are separated by `|`.'],
    'the comma' => [',', 'Fields to return: title.'],
]);

it('names the Query Builder integration beside the author of a described list', function (): void {
    $sort = allowListParameters('|', 'bracketed', [new QueryParameter('sort', description: 'Written by the author.')])['sort'];
    $records = array_column($sort['x-docuccino']['provenance'], 'fields', 'producer');

    expect($records['attribute'])->toBe(['description'])
        ->and($records['integration:query-builder'])->toContain('description');
});

/*
 * An endpoint that is both a Query Builder list and a JSON:API resource has two integrations writing one
 * `include`. Whichever description wins, the other's facts are facts about the same parameter, so they
 * follow it rather than vanish — the allow-list's values beneath JSON:API's prose, the comma beneath the
 * allow-list's.
 */
it('keeps one integration\'s facts beneath another integration\'s description of the same parameter', function (bool $queryBuilderFirst, string $expected): void {
    $facts = new QueryBuilderFacts;
    $facts->includes = [new QbEntry('posts', 'default')];
    $queryBuilder = static function (OperationDraft $operation) use ($facts): void {
        foreach ((new QueryBuilderParameters)->build($facts, new RepresentationPolicy) as $spec) {
            $spec->applyTo($operation->parameter('query', $spec->name), Contribution::integration('query-builder'));
        }
    };

    $parameters = attributeParameters([], static function (OperationDraft $operation) use ($queryBuilder, $queryBuilderFirst): void {
        if ($queryBuilderFirst) {
            $queryBuilder($operation);
        }
        JsonApiParameters::apply($operation, Contribution::integration('api-resources'));
        if (! $queryBuilderFirst) {
            $queryBuilder($operation);
        }
    });

    expect($parameters['include']['description'])->toBe($expected);
})->with([
    'the allow-list\'s description standing' => [true, "Include related resources: posts.\n\nValues are separated by `,`."],
    'JSON:API\'s description standing' => [false, "Comma-separated list of relationships to include as compound-document data.\n\nInclude related resources: posts."],
]);
