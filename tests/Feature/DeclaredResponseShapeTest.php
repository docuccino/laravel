<?php

declare(strict_types=1);

use Docuccino\Attributes\Response;
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
use Docuccino\Laravel\Extensions\AttributeResponsesExtension;

/**
 * The reported case, through the wiring that reported it: inference recovers a map body and
 * `#[Response(type: 'array{…}')]` declares a shape over it. The declaration is one shape, so what
 * described the map comes off with it.
 */
it('publishes a declared body as the shape the author declared', function (string $declared, array $expected): void {
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/things'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet([new Response(status: 200, type: $declared)]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(typeToSchema: DefaultTypeMappers::all()),
    );

    // What a `array<string, string>` return leaves behind: an object open to any key.
    $operation = new OperationDraft;
    $body = $operation->response('200')->content('application/json');
    $body->set('type', 'object', Contribution::inference());
    $body->set('additionalProperties', ['type' => 'string'], Contribution::inference());

    (new AttributeResponsesExtension)->handle($operation, $context);

    $schema = $operation->freeze()->responses['200']->content['application/json']['schema'] ?? [];
    unset($schema['x-docuccino']);

    expect($schema)->toBe($expected);
})->with([
    // The consumer's question is whether they may send a key the author did not name; a surviving
    // `additionalProperties` answers yes, of a shape that named its keys.
    'a closed shape over the map it replaced' => [
        'array{a: string, b: int}',
        [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']],
            'required' => ['a', 'b'],
        ],
    ],
    'a list over the map it replaced' => [
        'list<string>',
        ['type' => 'array', 'items' => ['type' => 'string']],
    ],
    'a scalar over the map it replaced' => [
        'string',
        ['type' => 'string'],
    ],
]);
