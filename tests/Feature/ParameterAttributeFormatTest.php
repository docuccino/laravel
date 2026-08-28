<?php

declare(strict_types=1);

use Docuccino\Attributes\CookieParameter;
use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\PathParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;

/*
 * The `format` argument every parameter attribute carries: it lands as the OAS `format` keyword on the
 * schema of whichever member the attribute patches — a flat parameter in any location, or a deepObject
 * container property — and an explicit `format` survives beside a `type` string's keywords.
 */

it('puts an attribute format on the parameter schema in every location', function (object $attribute, string $name): void {
    $params = attributeParameters([$attribute]);

    expect($params[$name]['schema']['format'])->toBe('date-time');
})->with([
    'query' => [new QueryParameter(name: 'from', format: 'date-time'), 'from'],
    'header' => [new HeaderParameter(name: 'X-Since', format: 'date-time'), 'X-Since'],
    'cookie' => [new CookieParameter(name: 'seen_at', format: 'date-time'), 'seen_at'],
    'path' => [new PathParameter(name: 'thing', format: 'date-time'), 'thing'],
]);

it('keeps an explicit format beside the type string it refines', function (): void {
    $params = attributeParameters([new QueryParameter(name: 'from', type: 'string', format: 'date')]);

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

    $params = attributeParameters([new QueryParameter(name: 'filter[since]', format: 'date-time')], $seed);

    expect($params['filter']['schema']['properties']['since']['format'])->toBe('date-time')
        ->and($params)->not->toHaveKey('filter[since]');
});
