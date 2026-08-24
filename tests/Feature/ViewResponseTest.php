<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Extensions\ViewMediaType;
use Docuccino\Laravel\Extensions\ViewTypeToSchema;
use Docuccino\Laravel\Support\FrameworkClasses;
use Docuccino\Laravel\Support\HtmlRepresentation;
use Docuccino\Laravel\Tests\Fixtures\Views\ThemedView;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Workbench\App\Http\Controllers\PageController;

/**
 * An action that renders a Blade template answers HTML, and a rendered view is transport the same way a
 * response object is — reflecting one publishes its `factory`, `engine` and `path` under
 * `application/json`, which is wrong twice over. These pin what it publishes instead: a `text/html` body
 * whose schema is a plain `string`, and no component at all.
 *
 * That the engine really hands a view back for `view('…')` — and narrows even a `View` *contract*
 * declaration to the concrete class — is proven separately, against the real analyser, in
 * ViewResponseReachabilityTest.
 */

/**
 * Metadata the real engine returns for a rendered view: the members `ClassTypeToSchema` would hoist if
 * nothing stopped it. Seeded so the mapper is proven to refuse a class it COULD have described.
 */
function viewMetadata(string $fqcn): ClassMetadata
{
    return new ClassMetadata($fqcn, [
        new PropertyMetadata('factory', new UnknownT('mixed'), 'The view factory instance.'),
        new PropertyMetadata('view', ScalarT::string()),
        new PropertyMetadata('path', ScalarT::string()),
    ]);
}

it('documents a rendered view as a text/html string and hoists no component', function (string $fqcn): void {
    [$responses, $document] = documentForReturn(new ClassT($fqcn), [$fqcn => viewMetadata($fqcn)]);

    $schema = $responses['200']['content'][HtmlRepresentation::MEDIA_TYPE]['schema'];
    unset($schema['x-docuccino']);

    expect(array_keys($responses['200']['content']))->toBe([HtmlRepresentation::MEDIA_TYPE])
        ->and($schema)->toBe(['type' => 'string'])
        ->and(typeSchemas($document))->toBe([]);
})->with(FrameworkClasses::VIEW_CLASSES);

it('recognises every listed view class', function (string $fqcn): void {
    expect(FrameworkClasses::isView($fqcn))->toBeTrue();
})->with(FrameworkClasses::VIEW_CLASSES);

it('recognises an app view subclass the list does not name', function (): void {
    // The list is deliberately not exhaustive: any loadable implementation of the contract counts, which
    // is what covers `class ThemedView extends View` in a real app.
    expect(FrameworkClasses::VIEW_CLASSES)->not->toContain(ThemedView::class)
        ->and(FrameworkClasses::isView(ThemedView::class))->toBeTrue();

    [$responses, $document] = documentForReturn(
        new ClassT(ThemedView::class),
        [ThemedView::class => viewMetadata(ThemedView::class)],
    );

    expect(array_keys($responses['200']['content']))->toBe([HtmlRepresentation::MEDIA_TYPE])
        ->and(typeSchemas($document))->toBe([]);
});

it('leaves a class that is not a view to hoist normally', function (string $fqcn, bool $expected): void {
    expect(FrameworkClasses::isView($fqcn))->toBe($expected);
})->with([
    'view contract' => [FrameworkClasses::VIEW_CONTRACT, true],
    'illuminate view' => ['Illuminate\\View\\View', true],
    'app subclass' => [ThemedView::class, true],
    // The unknown-entry contract. The view factory is the thing that MAKES views, not one of them, and
    // the response family next door is a separate refusal with a separate answer.
    'view factory' => ['Illuminate\\Contracts\\View\\Factory', false],
    'json response' => [FrameworkClasses::JSON_RESPONSE, false],
    'redirect' => [FrameworkClasses::REDIRECT_RESPONSE, false],
    'an app data class' => ['Workbench\\App\\Data\\WidgetData', false],
    'not a class at all' => ['App\\Nope\\Missing', false],
]);

it('says nothing about a view the author could act on', function (): void {
    // Nothing was lost that anyone can recover: markup has no structure the code proves. A diagnostic
    // here would fire on every HTML endpoint with no action available, which is how a channel stops
    // being read.
    [, , $diagnostics] = documentForReturn(new ClassT(FrameworkClasses::VIEW_CONTRACT));

    expect(array_map(static fn ($d): string => $d->code, $diagnostics))
        ->not->toContain('inferred-response.payload-unrecoverable')
        ->and(array_map(static fn ($d): string => $d->code, $diagnostics))
        ->not->toContain('lint.unpinned-redirect');
});

it('refuses a view wherever it turns up, not just at the top level', function (): void {
    // The refusal is a mapper, so it holds for every position — a `string` branch instead of a reflected
    // component, whether the view is the whole return or one member of a union.
    $converter = new SchemaConverter(
        [new ViewTypeToSchema, ...DefaultTypeMappers::all()],
        new NullTypeEngine,
        $components = new ComponentRegistry,
    );

    $schema = $converter->toSchema(UnionT::of([
        new ClassT(FrameworkClasses::VIEW_CONTRACT),
        new ClassT('Illuminate\\View\\View'),
    ]));

    expect($schema->schema)->toBe(['anyOf' => [['type' => 'string'], ['type' => 'string']]])
        ->and($components->schemas())->toBe([]);
});

it('defers on a type it does not support', function (): void {
    // The chain contract: a mapper handed something outside its supports() hands the type straight back
    // so the next one gets it, rather than swallowing it as a string.
    $mapper = new ViewTypeToSchema;
    $type = ScalarT::int();

    expect($mapper->supports($type))->toBeFalse()
        ->and($mapper->toSchema($type, new SchemaConverter([], new NullTypeEngine, new ComponentRegistry)))->toBeNull();
});

it('resolves text/html for a view payload and defers for anything else', function (): void {
    $resolver = new ViewMediaType;

    expect($resolver->mediaTypeFor(new ClassT(FrameworkClasses::VIEW_CONTRACT)))->toBe(HtmlRepresentation::MEDIA_TYPE)
        ->and($resolver->mediaTypeFor(new ClassT(ThemedView::class)))->toBe(HtmlRepresentation::MEDIA_TYPE)
        ->and($resolver->mediaTypeFor(new ClassT('Workbench\\App\\Data\\WidgetData')))->toBeNull()
        ->and($resolver->mediaTypeFor(ScalarT::string()))->toBeNull();
});

it('gives an action that negotiates HTML or JSON one content entry each', function (): void {
    // Both representations answer the same 200, and folding them into a single `anyOf` would file the
    // markup under a media type it contradicts.
    $data = 'Workbench\\App\\Data\\WidgetData';

    [$responses, $document] = documentForReturn(
        UnionT::of([new ClassT(FrameworkClasses::VIEW_CONTRACT), new ClassT($data)]),
        [$data => new ClassMetadata($data, [new PropertyMetadata('id', ScalarT::int())])],
    );

    $content = $responses['200']['content'];
    $html = $content[HtmlRepresentation::MEDIA_TYPE]['schema'];
    unset($html['x-docuccino']);

    // Sorted, so which entry is primary follows from the payloads and not from return-path order.
    expect(array_keys($content))->toBe(['application/json', HtmlRepresentation::MEDIA_TYPE])
        ->and($html)->toBe(['type' => 'string'])
        ->and($content['application/json']['schema']['$ref'] ?? null)->toBe('#/components/schemas/WidgetData')
        ->and(array_keys(typeSchemas($document)))->toBe(['WidgetData']);
});

it('carries the text/html body through validation and every OpenAPI version', function (): void {
    [, , $diagnostics, $result] = documentForReturn(new ClassT(FrameworkClasses::VIEW_CONTRACT));

    expect(array_map(static fn ($d): string => $d->code, $diagnostics))->not->toContain('document.schema-invalid');

    foreach ([new OpenApi32Emitter, new OpenApi31DownlevelEmitter, new OpenApi30DownlevelEmitter] as $emitter) {
        expect($emitter->emit($result->document))->toContain('"text/html"');
    }
});

it('documents a workbench controller that renders a Blade template as HTML', function (): void {
    // The whole pipeline over a real controller whose action is declared `: View` and returns `view(…)`.
    app('router')->get('api/dashboard', [PageController::class, 'dashboard']);
    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        classOverrides: ['Illuminate\\View\\View' => viewMetadata('Illuminate\\View\\View')],
        analysisOverrides: [
            PageController::class.'::dashboard' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Illuminate\\View\\View'), new SourceLocation(''))],
            ),
        ],
    ));

    $document = generateDocument()->document->toArray();
    $responses = $document['paths']['/api/dashboard']['get']['responses'];
    $schema = $responses['200']['content'][HtmlRepresentation::MEDIA_TYPE]['schema'];
    unset($schema['x-docuccino']);

    expect(array_keys($responses['200']['content']))->toBe([HtmlRepresentation::MEDIA_TYPE])
        ->and($schema)->toBe(['type' => 'string'])
        ->and($responses['200']['description'])->toBe('OK')
        ->and($document['components']['schemas'] ?? [])->not->toHaveKey('View');
});
