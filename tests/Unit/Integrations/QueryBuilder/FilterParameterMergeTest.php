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
 * The two producers of a bracketed filter parameter meeting on one GET operation: the Query Builder
 * names `filter[radius_lat]` off the allow-list, and a `Validator::make()` co-requirement rule set
 * (`filter.radius_lat` => numeric|between) types the same wire key. They must land on ONE parameter,
 * and the merged schema must be coherent — the Query Builder must not have pinned a guessed
 * `type: string` that a numeric bound then contradicts.
 */
it('merges a validated nested filter onto the query builder\'s bracketed parameter, coherently', function (): void {
    $op = new OperationDraft;

    // The Query Builder side: callback filters whose column the trace could not resolve, so nothing
    // types them.
    $facts = new QueryBuilderFacts;
    $facts->filters = [
        new QbEntry('radius_lat', 'callback'),
        new QbEntry('radius_lng', 'callback'),
        new QbEntry('radius_miles', 'callback'),
    ];

    foreach ((new QueryBuilderParameters)->build($facts, new RepresentationPolicy) as $spec) {
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

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/venues'),
        actionRef: new ActionRef('', 'App\\Venues', 'index'),
        attributes: new AttributeSet([]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        components: new ComponentRegistry,
    );

    (new RecoveredRequest)->apply(
        $op,
        $context,
        new ValidationSchema($builder->build(new RepresentationPolicy)),
        'inline-rules',
    );

    $names = array_map(static fn ($parameter): ?string => $parameter->name, $op->freeze()->parameters);

    expect($names)->toBe(['filter[radius_lat]', 'filter[radius_lng]', 'filter[radius_miles]'])
        // No unbracketed `filter` object duplicating the three bracketed params.
        ->and($op->hasParameter('query', 'filter'))->toBeFalse();

    $latitude = $op->parameter('query', 'filter[radius_lat]')->freeze()->toArray();
    unset($latitude['schema']['x-docuccino']);

    // Coherent: the numeric bounds sit on a numeric type, not on a shadowing `type: string`.
    expect($latitude['schema'])->toBe(['type' => 'number', 'minimum' => -90, 'maximum' => 90])
        ->and($latitude['description'])->toBe('Custom filter');
});
