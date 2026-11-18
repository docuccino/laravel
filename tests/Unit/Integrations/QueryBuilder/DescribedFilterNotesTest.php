<?php

declare(strict_types=1);

use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\QueryBuilder\QbEntry;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderFacts;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParameters;

/*
 * A filter matched as `whereIn` takes a list, and a nullable one takes `null` to match absent values. Its
 * schema cannot say either, so its description does — and that stays true whatever prose describes the
 * filter, so an author's `#[QueryParameter('filter[…]', description: …)]` leads and the notes stand
 * beneath it. Where nobody wrote one, the filter reads as it always has: one paragraph.
 */
it('keeps what a filter accepts beneath the description its author writes, in either representation', function (string $style, ?string $described, string $expected): void {
    $facts = new QueryBuilderFacts;
    $facts->filters = [new QbEntry('status', 'exact', nullable: true, columnSchema: ['type' => 'string', 'enum' => ['open', 'closed']], enumTyped: true)];

    $parameters = attributeParameters(
        $described === null ? [] : [new QueryParameter('filter[status]', description: $described)],
        static function (OperationDraft $operation) use ($facts, $style): void {
            foreach ((new QueryBuilderParameters)->build($facts, new RepresentationPolicy(filterStyle: $style)) as $spec) {
                $spec->applyTo($operation->parameter('query', $spec->name), Contribution::integration('query-builder'));
            }
        },
    );

    $published = $style === 'deepObject'
        ? $parameters['filter']['schema']['properties']['status']['description']
        : $parameters['filter[status]']['description'];

    expect($published)->toBe($expected);
})->with([
    'bracketed, undescribed' => ['bracketed', null, 'Exact match on `status`. Accepts a comma-separated list of values (matched as `whereIn`). An empty value matches rows that have no value.'],
    'bracketed, described' => ['bracketed', 'The state to list.', "The state to list.\n\nAccepts a comma-separated list of values (matched as `whereIn`). An empty value matches rows that have no value."],
    'deepObject, undescribed' => ['deepObject', null, 'Exact match on `status`. Accepts a comma-separated list of values (matched as `whereIn`). An empty value matches rows that have no value.'],
    'deepObject, described' => ['deepObject', 'The state to list.', "The state to list.\n\nAccepts a comma-separated list of values (matched as `whereIn`). An empty value matches rows that have no value."],
]);

it('names the filter integration beside the author of a described filter', function (): void {
    $facts = new QueryBuilderFacts;
    $facts->filters = [new QbEntry('status', 'exact', nullable: true)];

    $parameters = attributeParameters(
        [new QueryParameter('filter[status]', description: 'The state to list.')],
        static function (OperationDraft $operation) use ($facts): void {
            foreach ((new QueryBuilderParameters)->build($facts, new RepresentationPolicy) as $spec) {
                $spec->applyTo($operation->parameter('query', $spec->name), Contribution::integration('query-builder'));
            }
        },
    );

    $records = array_column($parameters['filter[status]']['x-docuccino']['provenance'], 'fields', 'producer');

    expect($records['attribute'])->toBe(['description'])
        ->and($records['integration:query-builder'])->toContain('description');
});
