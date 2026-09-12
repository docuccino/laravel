<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Validation\RecoveredRequest;
use Docuccino\Core\Extensions\Validation\RequestSchemaBuilder;
use Docuccino\Core\Extensions\Validation\ValidationSchema;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\QueryBuilder\QbEntry;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderFacts;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParameters;

/**
 * The two producers of one filter surface meeting on a GET operation: the Query Builder names the
 * filters off the allow-list, and a `Validator::make()` co-requirement rule set (`filter.radius_lat` =>
 * numeric|between) types the same wire keys. They must land on ONE parameter under EITHER
 * representation — `representation.filters` chooses the wire form the whole surface takes, and a
 * parameter published in the other form is not a form the server accepts under that setting.
 *
 * @param  list<QbEntry>  $filters
 * @return array<string, array<string, mixed>>
 */
function mergedFilterParameters(array $filters, string $representation): array
{
    $policy = new RepresentationPolicy(filterStyle: $representation);

    $op = new OperationDraft;
    $facts = new QueryBuilderFacts;
    $facts->filters = $filters;

    foreach ((new QueryBuilderParameters)->build($facts, $policy) as $spec) {
        $spec->applyTo($op->parameter('query', $spec->name), Contribution::integration('query-builder'));
    }

    // The validation side: the app validates the co-required filters in a separate method.
    $builder = new RequestSchemaBuilder;
    $builder->field('filter.radius_lat')->setType('number');
    $builder->field('filter.radius_lat')->set('minimum', -90);
    $builder->field('filter.radius_lat')->set('maximum', 90);
    $builder->field('filter.radius_lng')->setType('number');
    $builder->field('filter.radius_miles')->setType('integer');
    $builder->field('filter.radius_miles')->set('minimum', 1);
    // Not under any container, so nothing about the representation moves it.
    $builder->field('page')->setType('integer');

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/venues'),
        actionRef: new ActionRef('', 'App\\Venues', 'index'),
        attributes: new AttributeSet([]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', [], representation: ['filters' => $representation]),
        components: new ComponentRegistry,
    );

    (new RecoveredRequest)->apply($op, $context, new ValidationSchema($builder->build($policy)), 'inline-rules');

    $byName = [];
    foreach ($op->freeze()->parameters as $parameter) {
        $byName[(string) $parameter->name] = $parameter->toArray();
    }

    return $byName;
}

/**
 * The three app-handled filters both cases are made over — callbacks whose column nothing resolved.
 *
 * @return list<QbEntry>
 */
function radiusFilters(): array
{
    return [new QbEntry('radius_lat', 'callback'), new QbEntry('radius_lng', 'callback'), new QbEntry('radius_miles', 'callback')];
}

it('merges a validated nested filter onto the query builder\'s bracketed parameter, coherently', function (): void {
    $params = mergedFilterParameters(radiusFilters(), 'bracketed');

    expect(array_keys($params))->toBe(['filter[radius_lat]', 'filter[radius_lng]', 'filter[radius_miles]', 'page']);

    $latitude = $params['filter[radius_lat]'];
    unset($latitude['schema']['x-docuccino']);

    // Coherent: the numeric bounds sit on a numeric type, not on a shadowing `type: string`.
    expect($latitude['schema'])->toBe(['type' => 'number', 'minimum' => -90, 'maximum' => 90])
        ->and($latitude['description'])->toBe('Filters the result set by `radius_lat`.');
});

it('merges a validated nested filter onto the query builder\'s deepObject container, coherently', function (): void {
    $params = mergedFilterParameters(radiusFilters(), 'deepObject');

    // The container is the whole filter surface: no bracketed parameter beside it restating a member,
    // which is what a consumer would read as a second input and a generated client might also send.
    expect(array_keys($params))->toBe(['filter', 'page']);

    $properties = $params['filter']['schema']['properties'];
    $latitude = $properties['radius_lat'];
    unset($latitude['x-docuccino']);

    expect($latitude)->toBe([
        'description' => 'Filters the result set by `radius_lat`.',
        'type' => 'number',
        'minimum' => -90,
        'maximum' => 90,
    ])
        ->and($properties['radius_lng']['type'])->toBe('number')
        ->and($properties['radius_miles'])->toMatchArray(['type' => 'integer', 'minimum' => 1]);
});

it('leaves a validated key no container claims as its own parameter', function (): void {
    // One rule set, one reading: only the key a published container claims stands down. `page` is
    // under nothing, so the representation does not move it.
    $params = mergedFilterParameters(radiusFilters(), 'deepObject');

    expect($params['page']['schema']['type'])->toBe('integer');

    // With no allow-list there is no container to merge into, so every validated key keeps the
    // bracketed spelling — the reading is inert rather than a second grammar for the same question.
    expect(array_keys(mergedFilterParameters([], 'deepObject')))
        ->toBe(['filter[radius_lat]', 'filter[radius_lng]', 'filter[radius_miles]', 'page']);
});
