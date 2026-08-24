<?php

declare(strict_types=1);

use Docuccino\Attributes\PathParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Extensions\PathParametersExtension;

/**
 * `route-binding.untyped` reports a bound segment nothing could type, and the `#[PathParameter]` its help
 * names is applied a whole extension later. Reading that attribute here is what keeps the notice true —
 * an author who declared the type has nothing left to do.
 */
function untypedBindingCodes(array $attributes): array
{
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/things/{thing}'),
        actionRef: new ActionRef('', null, 'show'),
        attributes: new AttributeSet($attributes),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(typeToSchema: DefaultTypeMappers::all()),
        pathParameters: ['thing'],
        // A bound class no resolver answers for: the eloquent integration off, a custom UrlRoutable.
        routeBindings: ['thing' => 'Workbench\\App\\Support\\Thing'],
    );

    (new PathParametersExtension)->handle(new OperationDraft, $context);

    return array_map(static fn ($d): string => $d->code, $context->components->diagnostics());
}

it('reports a bound segment nothing could type', function (): void {
    expect(untypedBindingCodes([]))->toContain('route-binding.untyped');
});

it('stays silent once an attribute declares the segment type', function (): void {
    expect(untypedBindingCodes([new PathParameter('thing', type: 'int')]))
        ->not->toContain('route-binding.untyped');
});

it('still reports where the declaration settles some other segment, or no type at all', function (object $attribute): void {
    // A PathParameter naming another segment says nothing about this one, and one with no `type:`
    // describes the parameter without typing it — which is exactly what the notice is about.
    expect(untypedBindingCodes([$attribute]))->toContain('route-binding.untyped');
})->with([
    'another segment' => [new PathParameter('other', type: 'int')],
    'a description only' => [new PathParameter('thing', description: 'The thing.')],
]);
