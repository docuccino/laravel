<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\BuiltIn\EnumSchema;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Tests\Support\QbTraceScript;

/**
 * A user-land filter factory (a `ListFilters`-style helper returning a Spatie `AllowedFilter`) is typed
 * from its call-site arguments, with no descent into the factory body. A backed-enum class-string
 * argument types the value off that enum directly; otherwise the filter's own key is the column, typed
 * off the model cast. Spatie's own factories and bare strings are untouched. This runs the real extension
 * over a scripted trace — the enum/model reflection is real, and the engine descent is proven in the
 * fixture group.
 */
function runFactoryFilters(string $chain): array
{
    $engine = new StubTypeEngine(traces: [
        'App\\Gadgets::index' => QbTraceScript::forChain($chain),
    ]);

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: new ActionRef('', 'App\\Gadgets', 'index'),
        attributes: new AttributeSet,
        engine: $engine,
        document: new DocumentConfig('default', []),
        // EnumSchema (backing values + x-enumDescriptions) is an adapter mapper the production pipeline
        // registers ahead of the core case-names mapper — mirrored here so enums type as they do in prod.
        typeMappers: [new EnumSchema, ...DefaultTypeMappers::all()],
    );

    (new QueryBuilderParametersExtension)->handle($operation = new OperationDraft, $context);

    $byName = [];
    foreach ($operation->freeze()->parameters as $parameter) {
        $byName[$parameter->name] = $parameter->toArray();
    }
    $byName['__components'] = $context->components->schemas();

    return $byName;
}

$factoryChain = 'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
    ."\\Workbench\\App\\Support\\FilterFactory::enum('status', \\Workbench\\App\\Enums\\WidgetStatus::class), "  // enum arg → enum typed
    ."\\Workbench\\App\\Support\\FilterFactory::boolean('active'), "                                             // column=key → model bool cast
    ."\\Workbench\\App\\Support\\FilterFactory::uuid('public_id'), "                                             // column=key → model string cast
    ."\\Workbench\\App\\Support\\FilterFactory::uuid('gadget', 'public_id'), "                                   // explicit 2nd arg → that column's cast
    ."\\Workbench\\App\\Support\\FilterFactory::search('q', ['name']), "                                         // no single column → string
    ."AllowedFilter::partial('name'),"                                                                          // Spatie's own → untouched (string)
    .'])->paginate()';

it('types an enum-factory filter off its backed-enum class-string argument (scalar enum, not whereIn)', function () use ($factoryChain): void {
    $byName = runFactoryFilters($factoryChain);

    // The enum hoists to a component (default policy) and the filter's value $refs it. This is a
    // single-value comparison, so the $ref sits directly on the schema, not inside a whereIn array.
    expect($byName['filter[status]']['schema']['$ref'])->toBe('#/components/schemas/WidgetStatus')
        ->and($byName['filter[status]']['schema'])->not->toHaveKey('items')
        ->and($byName['__components']['WidgetStatus'])->toBe([
            'type' => 'string',
            'enum' => ['draft', 'published', 'archived'],
            'x-enumDescriptions' => [
                'draft' => 'Not yet visible to applicants.',
                'published' => 'Live and accepting traffic.',
            ],
        ]);
});

it('types a boolean-factory filter off the model cast for its key column', function () use ($factoryChain): void {
    $byName = runFactoryFilters($factoryChain);

    expect($byName['filter[active]']['schema']['type'])->toBe('boolean');
});

it('types a uuid-factory filter off the model cast, by key and by explicit column argument', function () use ($factoryChain): void {
    // uuid() types like boolean(): the filter's own key is the column by default, and an explicit second
    // argument names a different one. Both resolve off the model cast (`public_id => 'string'`), and
    // neither invents an enum domain.
    $byName = runFactoryFilters($factoryChain);

    expect($byName['filter[public_id]']['schema']['type'])->toBe('string')
        ->and($byName['filter[public_id]']['schema'])->not->toHaveKey('enum')
        // In `uuid('gadget', 'public_id')` the first arg is the filter key, the second the column.
        ->and($byName['filter[gadget]']['schema']['type'])->toBe('string')
        ->and($byName['filter[gadget]']['schema'])->not->toHaveKey('enum');
});

it('leaves a multi-column search factory and Spatie\'s own factories as plain strings', function () use ($factoryChain): void {
    $byName = runFactoryFilters($factoryChain);

    expect($byName['filter[q]']['schema']['type'])->toBe('string')
        ->and($byName['filter[q]']['schema'])->not->toHaveKey('enum')
        // Spatie's own AllowedFilter::partial isn't a project factory, so it's unaffected.
        ->and($byName['filter[name]']['schema']['type'])->toBe('string')
        ->and($byName['filter[name]']['schema'])->not->toHaveKey('enum');
});

it('leaves an enum-factory filter a plain string when the subject model is unresolvable', function (): void {
    // Without a `QueryBuilder::for(Model::class)` origin there's no subject model. The enum-arg path
    // doesn't need one, but a factory with no enum arg falls back to the key→cast lookup, which does —
    // so it degrades to string.
    $byName = runFactoryFilters(
        'QueryBuilder::for($builder)->allowedFilters([\\Workbench\\App\\Support\\FilterFactory::boolean(\'active\')])->paginate()',
    );

    expect($byName['filter[active]']['schema']['type'])->toBe('string')
        ->and($byName['filter[active]']['schema'])->not->toHaveKey('enum');
});
