<?php

declare(strict_types=1);

use Docuccino\Attributes\CookieParameter;
use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\PathParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Extensions\AttributeParametersExtension;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;

/**
 * The `format` argument every parameter attribute carries: it lands as the OAS `format` keyword on the
 * schema of whichever member the attribute patches — a flat parameter in any location, or a deepObject
 * container property — and an explicit `format` survives beside a `type` string's keywords.
 */
function runFormatAttributes(array $attributes, ?callable $seed = null): array
{
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/things/{thing}'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet($attributes),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
        ),
    );

    $operation = new OperationDraft;
    if ($seed !== null) {
        $seed($operation);
    }
    (new AttributeParametersExtension)->handle($operation, $context);

    $byName = [];
    foreach ($operation->freeze()->parameters as $parameter) {
        $byName[$parameter->name] = $parameter->toArray();
    }

    return $byName;
}

it('puts an attribute format on the parameter schema in every location', function (object $attribute, string $name): void {
    $params = runFormatAttributes([$attribute]);

    expect($params[$name]['schema']['format'])->toBe('date-time');
})->with([
    'query' => [new QueryParameter(name: 'from', format: 'date-time'), 'from'],
    'header' => [new HeaderParameter(name: 'X-Since', format: 'date-time'), 'X-Since'],
    'cookie' => [new CookieParameter(name: 'seen_at', format: 'date-time'), 'seen_at'],
    'path' => [new PathParameter(name: 'thing', format: 'date-time'), 'thing'],
]);

it('keeps an explicit format beside the type string it refines', function (): void {
    $params = runFormatAttributes([new QueryParameter(name: 'from', type: 'string', format: 'date')]);

    expect($params['from']['schema']['type'])->toBe('string')
        ->and($params['from']['schema']['format'])->toBe('date');
});

it('puts a bracketed attribute format on the deepObject property it patches', function (): void {
    $seed = static function (OperationDraft $operation): void {
        (new QueryParameterSpec(
            name: 'filter',
            schema: ['type' => 'object', 'properties' => ['since' => ['type' => 'string']]],
            style: 'deepObject',
            explode: true,
        ))->applyTo($operation->parameter('query', 'filter'), Contribution::integration('query-builder'));
    };

    $params = runFormatAttributes([new QueryParameter(name: 'filter[since]', format: 'date-time')], $seed);

    expect($params['filter']['schema']['properties']['since']['format'])->toBe('date-time')
        ->and($params)->not->toHaveKey('filter[since]');
});
