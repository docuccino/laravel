<?php

declare(strict_types=1);

use Docuccino\Attributes\BodyParameter;
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
use Docuccino\Laravel\Extensions\AttributeRequestBodyExtension;

/**
 * `#[BodyParameter]` semantics (docs §1.5): the attribute PATCHES a single property of the inferred
 * request body — its named property is added/overridden while every inferred sibling is kept — rather
 * than replacing the whole body. With no inferred body the attributes create one. Regression guard for
 * the historical whole-body replacement.
 */
function runBodyParameters(array $attributes, ?callable $seedInferred = null): array
{
    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/things'),
        actionRef: new ActionRef('', null, 'store'),
        attributes: new AttributeSet($attributes),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
        ),
    );

    $operation = new OperationDraft;
    if ($seedInferred !== null) {
        $seedInferred($operation);
    }
    (new AttributeRequestBodyExtension)->handle($operation, $context);

    /** @var array<string, mixed> $body */
    $body = $operation->resolvedField('requestBody') ?? [];

    return $body;
}

it('patches one inferred body property while keeping inferred siblings', function (): void {
    // A body inferred at the integration layer (e.g. a FormRequest), carrying two properties.
    $seed = function (OperationDraft $operation): void {
        $operation->set('requestBody', [
            'required' => true,
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'body' => ['type' => 'string'],
                ],
                'required' => ['title'],
            ]]],
        ], Contribution::integration('form-request'));
    };

    $body = runBodyParameters([
        new BodyParameter(name: 'title', type: 'int', description: 'The numeric id', required: true),
    ], $seed);

    $schema = $body['content']['application/json']['schema'];

    // The inferred sibling `body` is untouched...
    expect($schema['properties']['body'])->toBe(['type' => 'string'])
        // ...while `title` is overridden by the attribute (now int + description).
        ->and($schema['properties']['title'])->toBe(['type' => 'integer', 'description' => 'The numeric id'])
        // Both keys survive — the whole body was NOT replaced.
        ->and(array_keys($schema['properties']))->toBe(['title', 'body'])
        ->and($schema['required'])->toContain('title');
});

it('creates a request body from attributes when none was inferred', function (): void {
    $body = runBodyParameters([
        new BodyParameter(name: 'note', description: 'A free-text note', required: true),
    ]);

    $schema = $body['content']['application/json']['schema'];

    expect($body['required'])->toBeTrue()
        ->and($schema)->toBe([
            'type' => 'object',
            'properties' => ['note' => ['type' => 'string', 'description' => 'A free-text note']],
            'required' => ['note'],
        ]);
});
