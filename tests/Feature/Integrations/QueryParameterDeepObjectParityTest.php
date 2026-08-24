<?php

declare(strict_types=1);

use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Extensions\AttributeParametersExtension;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;

/**
 * Bracketed / deepObject PARITY for `#[QueryParameter('filter[status]')]`: the two representations
 * express the same filter map differently (a flat `filter[status]` parameter vs a `filter` deepObject
 * container with a `status` property), so the bracketed attribute must patch whichever exists. Both
 * apply every field and both create a missing member — only the LANDING LOCATION differs. Dataset over
 * the two policies with identical attribute inputs (the parity table).
 */
function paritySeedDeepObject(OperationDraft $operation, Contribution $by): void
{
    // What the QB integration emits under the deepObject policy: a `filter` container whose `status`
    // property is a nested draft carrying integration provenance.
    (new QueryParameterSpec(
        name: 'filter',
        schema: ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'description' => 'Exact match on `status`.']]],
        description: 'Filter the result set.',
        style: 'deepObject',
        explode: true,
    ))->applyTo($operation->parameter('query', 'filter'), $by);
}

function paritySeedBracketed(OperationDraft $operation, Contribution $by): void
{
    // What the QB integration emits under the bracketed policy: a flat `filter[status]` parameter.
    (new QueryParameterSpec(
        name: 'filter[status]',
        schema: ['type' => 'string'],
        description: 'Exact match on `status`.',
    ))->applyTo($operation->parameter('query', 'filter[status]'), $by);
}

function runParity(callable $seed, string $attributeName, bool $required = false): array
{
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/x'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet([new QueryParameter(
            name: $attributeName,
            description: 'Only active records.',
            required: $required,
            default: 'active',
            example: 'active',
        )]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
    );

    $operation = new OperationDraft;
    $seed($operation, Contribution::integration('query-builder'));
    (new AttributeParametersExtension)->handle($operation, $context);

    $byName = [];
    foreach ($operation->freeze()->parameters as $parameter) {
        $byName[$parameter->name] = $parameter->toArray();
    }

    return $byName;
}

it('patches the matching member in each representation, applying every field to its natural location', function (string $policy): void {
    $required = true;

    if ($policy === 'deepObject') {
        $params = runParity(paritySeedDeepObject(...), 'filter[status]', $required);

        // No flat param was created; the container's `status` property was patched.
        expect($params)->toHaveKey('filter')->and($params)->not->toHaveKey('filter[status]');

        $status = $params['filter']['schema']['properties']['status'];
        expect($status['description'])->toBe('Only active records.')
            ->and($status['default'])->toBe('active')
            ->and($status['example'])->toBe('active')
            ->and($params['filter']['schema']['required'])->toContain('status');

        return;
    }

    $params = runParity(paritySeedBracketed(...), 'filter[status]', $required);

    // No container was created; the flat `filter[status]` parameter was patched.
    expect($params)->toHaveKey('filter[status]')->and($params)->not->toHaveKey('filter');
    expect($params['filter[status]']['description'])->toBe('Only active records.')
        ->and($params['filter[status]']['schema']['default'])->toBe('active')
        ->and($params['filter[status]']['example'])->toBe('active')
        ->and($params['filter[status]']['required'])->toBeTrue();
})->with(['deepObject', 'bracketed']);

it('creates a missing member in each representation (create-on-miss parity)', function (string $policy): void {
    if ($policy === 'deepObject') {
        $params = runParity(paritySeedDeepObject(...), 'filter[unknown]');

        // The property is created under the existing container — no flat param.
        expect($params['filter']['schema']['properties'])->toHaveKey('unknown')
            ->and($params)->not->toHaveKey('filter[unknown]');
        expect($params['filter']['schema']['properties']['unknown']['description'])->toBe('Only active records.');

        return;
    }

    // No container exists (bracketed): the flat parameter is created, exactly as before.
    $params = runParity(paritySeedBracketed(...), 'filter[unknown]');
    expect($params)->toHaveKey('filter[unknown]')
        ->and($params['filter[unknown]']['description'])->toBe('Only active records.');
})->with(['deepObject', 'bracketed']);

it('records attribute-layer provenance on the patched deepObject property (overrode kept)', function (): void {
    $params = runParity(paritySeedDeepObject(...), 'filter[status]', true);

    $provenance = $params['filter']['schema']['properties']['status']['x-docuccino']['provenance'];
    $layers = array_map(static fn (array $r): string => $r['layer'], $provenance);

    // The property now carries the attribute layer; the integration's original description is kept as
    // an overridden entry rather than lost.
    expect($layers)->toContain('attribute');
    $attributeRecord = array_values(array_filter($provenance, static fn (array $r): bool => $r['layer'] === 'attribute'))[0];
    expect($attributeRecord['overrode'] ?? [])->not->toBe([]);
});
