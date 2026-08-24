<?php

declare(strict_types=1);

use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Extensions\AttributeParametersExtension;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Tests\Support\TraceScript;

/**
 * End-to-end proof that a SHARED custom filter class can declare its schema once, class-level, for
 * every call site: a project factory's body is folded to the `AllowedFilter::custom` it wraps, so the
 * wrapped class's `#[QueryParameter]` (format included) lands on the entry; an unfoldable factory
 * falls back to its own class attribute; and nothing anywhere degrades to a guess. Scripted trace over
 * real workbench fixtures — the engine's own fold is proven in the fixture group.
 *
 * @param  list<object>  $attributes  route-level attributes, applied by the layer above afterwards
 */
function runFactoryClassAttribute(string $chain, array $foldedReturns = [], array $attributes = []): array
{
    $engine = new StubTypeEngine(traces: [
        'App\\Gadgets::index' => TraceScript::forChain($chain, foldedReturns: $foldedReturns),
    ]);

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: new ActionRef('', 'App\\Gadgets', 'index'),
        attributes: new AttributeSet($attributes),
        engine: $engine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(typeToSchema: DefaultTypeMappers::all()),
    );

    $operation = new OperationDraft;
    (new QueryBuilderParametersExtension)->handle($operation, $context);
    // The attribute layer runs after the integration one, as the resolved order has it.
    (new AttributeParametersExtension)->handle($operation, $context);

    $byName = [];
    foreach ($operation->freeze()->parameters as $parameter) {
        $byName[$parameter->name] = $parameter->toArray();
    }

    return [$byName, $context->dependencyFiles()];
}

it('applies the wrapped filter class attribute through its own static factory (the new-self idiom)', function (): void {
    [$byName, $dependencyFiles] = runFactoryClassAttribute(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
            ."\\Workbench\\App\\Filters\\PublicIdFilter::allowed('position_id'),"
            .'])->paginate()',
        foldedReturns: [
            'allowed' => TraceScript::foldOf("\\Spatie\\QueryBuilder\\AllowedFilter::custom('position_id', new \\Workbench\\App\\Filters\\PublicIdFilter, 'position_id')"),
        ],
    );

    // The class-level attribute is the one declaration: type + format + description, on every call site.
    expect($byName['filter[position_id]']['schema']['type'])->toBe('string')
        ->and($byName['filter[position_id]']['schema']['format'])->toBe('uuid')
        ->and($byName['filter[position_id]']['description'])->toBe('A uuid public identifier.');

    // Cache soundness: the filter class file joins the dependency set, so editing it re-documents.
    expect(array_map('basename', $dependencyFiles))->toContain('PublicIdFilter.php');
});

it('applies the wrapped filter class attribute through a wrapper factory on another class', function (): void {
    // Factory class ≠ filter class (`ListFilters::uuid(...)` shape): the attribute lives on the WRAPPED
    // class, reachable only through the body fold.
    [$byName] = runFactoryClassAttribute(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
            ."\\Workbench\\App\\Support\\FilterFactory::publicId('owner_id'),"
            .'])->paginate()',
        foldedReturns: [
            'publicId' => TraceScript::foldOf("\\Spatie\\QueryBuilder\\AllowedFilter::custom('owner_id', new \\Workbench\\App\\Filters\\PublicIdFilter, 'owner_id')"),
        ],
    );

    expect($byName['filter[owner_id]']['schema']['type'])->toBe('string')
        ->and($byName['filter[owner_id]']['schema']['format'])->toBe('uuid')
        ->and($byName['filter[owner_id]']['description'])->toBe('A uuid public identifier.');
});

it('falls back to the factory class own attribute when its body cannot fold, format riding the base string', function (): void {
    // No entry in foldedReturns: the engine declines a branching body. The attribute carries format
    // WITHOUT type, which must ride on the base string schema.
    [$byName, $dependencyFiles] = runFactoryClassAttribute(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
            ."\\Workbench\\App\\Filters\\LegacyKeyFilter::allowed('legacy_id'),"
            .'])->paginate()',
    );

    expect($byName['filter[legacy_id]']['schema']['type'])->toBe('string')
        ->and($byName['filter[legacy_id]']['schema']['format'])->toBe('ulid')
        ->and($byName['filter[legacy_id]']['description'])->toBe('A legacy ulid key.');

    expect(array_map('basename', $dependencyFiles))->toContain('LegacyKeyFilter.php');
});

it('keeps call-site column typing for a factory with no attribute anywhere', function (): void {
    // Unfoldable AND attribute-less → exactly the pre-existing behavior: the key column's boolean cast.
    [$byName] = runFactoryClassAttribute(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
            ."\\Workbench\\App\\Support\\FilterFactory::boolean('active'),"
            .'])->paginate()',
    );

    expect($byName['filter[active]']['schema']['type'])->toBe('boolean')
        // A project factory's method is not a kind this adapter knows, so the prose claims no
        // match semantics — only that the parameter filters, and on which public key.
        ->and($byName['filter[active]']['description'])->toBe('Filters the result set by `active`.');
});

it('ignores a fold that answers with a non-custom factory kind', function (): void {
    // The body returns AllowedFilter::exact — new information the call site already typed better; the
    // upgrade only fires for a wrapped custom filter class.
    [$byName] = runFactoryClassAttribute(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
            ."\\Workbench\\App\\Support\\FilterFactory::boolean('active'),"
            .'])->paginate()',
        foldedReturns: [
            'boolean' => TraceScript::foldOf("\\Spatie\\QueryBuilder\\AllowedFilter::exact('active')"),
        ],
    );

    expect($byName['filter[active]']['schema']['type'])->toBe('boolean');
});

it('applies format from a class attribute on a directly registered custom filter', function (): void {
    // No factory in sight: AllowedFilter::custom at the call site, format read off the class
    // attribute exactly as the route-level layer would read it.
    [$byName] = runFactoryClassAttribute(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
            ."AllowedFilter::custom('pub', \\Workbench\\App\\Filters\\PublicIdFilter::class),"
            .'])->paginate()',
    );

    expect($byName['filter[pub]']['schema']['type'])->toBe('string')
        ->and($byName['filter[pub]']['schema']['format'])->toBe('uuid')
        ->and($byName['filter[pub]']['description'])->toBe('A uuid public identifier.');
});

/**
 * The class attribute speaks for EVERY call site, so what one entry says about itself is narrower and
 * wins: a comment above the entry over `description`, a chained `->default()` over `default`. Both
 * facts arrive at the integration layer, so the four-way matrix below is the whole rule.
 */
$stageChain = static fn (string $entry): string => "QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters([\n"
    .'    '.$entry."\n"
    .'])->paginate()';

it('publishes the entry comment over the shared class attribute description', function () use ($stageChain): void {
    [$byName] = runFactoryClassAttribute($stageChain(
        "// Only gadgets at this stage of assembly.\n"
        ."    AllowedFilter::custom('stage', \\Workbench\\App\\Filters\\StageFilter::class),"
    ));

    // The comment wins; everything else the class declares still lands on the parameter.
    expect($byName['filter[stage]']['description'])->toBe('Only gadgets at this stage of assembly.')
        ->and($byName['filter[stage]']['schema']['type'])->toBe('string')
        ->and($byName['filter[stage]']['schema']['default'])->toBe('sent');
});

it('publishes the class attribute description when the entry carries no comment', function () use ($stageChain): void {
    [$byName] = runFactoryClassAttribute($stageChain(
        "AllowedFilter::custom('stage', \\Workbench\\App\\Filters\\StageFilter::class),"
    ));

    expect($byName['filter[stage]']['description'])->toBe('The lifecycle stage.');
});

it('publishes the entry comment when no class attribute contests it', function () use ($stageChain): void {
    [$byName] = runFactoryClassAttribute($stageChain(
        "// Matches on score or activity.\n"
        ."    AllowedFilter::custom('opaque', \\Workbench\\App\\Filters\\CompositeFilter::class),"
    ));

    expect($byName['filter[opaque]']['description'])->toBe('Matches on score or activity.');
});

it('falls back to the generated contract prose with neither a comment nor a class attribute', function () use ($stageChain): void {
    [$byName] = runFactoryClassAttribute($stageChain(
        "AllowedFilter::custom('opaque', \\Workbench\\App\\Filters\\CompositeFilter::class),"
    ));

    expect($byName['filter[opaque]']['description'])->toBe('Filters the result set by `opaque`.');
});

it('publishes a chained default over the shared class attribute default', function () use ($stageChain): void {
    [$byName] = runFactoryClassAttribute($stageChain(
        "AllowedFilter::custom('stage', \\Workbench\\App\\Filters\\StageFilter::class)->default('draft'),"
    ));

    expect($byName['filter[stage]']['schema']['default'])->toBe('draft')
        ->and($byName['filter[stage]']['description'])->toBe('The lifecycle stage.');
});

it('publishes a chained default when no class attribute contests it', function () use ($stageChain): void {
    [$byName] = runFactoryClassAttribute($stageChain(
        "AllowedFilter::custom('opaque', \\Workbench\\App\\Filters\\CompositeFilter::class)->default('draft'),"
    ));

    expect($byName['filter[opaque]']['schema']['default'])->toBe('draft');
});

it('publishes no default at all when neither the entry nor the class names one', function () use ($stageChain): void {
    [$byName] = runFactoryClassAttribute($stageChain(
        "AllowedFilter::custom('opaque', \\Workbench\\App\\Filters\\CompositeFilter::class),"
    ));

    expect($byName['filter[opaque]']['schema'])->not->toHaveKey('default');
});

it('still lets a route-level attribute override a published class-level description', function () use ($stageChain): void {
    // The narrower-claim rule resolves comment vs class attribute INSIDE the integration layer; the
    // ladder above it is untouched, so the action's own attribute still has the last word.
    [$byName] = runFactoryClassAttribute(
        $stageChain("AllowedFilter::custom('stage', \\Workbench\\App\\Filters\\StageFilter::class),"),
        attributes: [new QueryParameter('filter[stage]', description: 'Stages this endpoint exposes.')],
    );

    expect($byName['filter[stage]']['description'])->toBe('Stages this endpoint exposes.');
});

it('lets an explicit format override the one the type implied', function (): void {
    // `type: 'date'` implies `format: date`; the attribute's own format is the more precise claim and
    // must win, exactly as it does at route level.
    [$byName] = runFactoryClassAttribute(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
            ."AllowedFilter::custom('archived_since', \\Workbench\\App\\Filters\\ArchivedSinceFilter::class),"
            .'])->paginate()',
    );

    expect($byName['filter[archived_since]']['schema']['type'])->toBe('string')
        ->and($byName['filter[archived_since]']['schema']['format'])->toBe('date-time')
        ->and($byName['filter[archived_since]']['description'])->toBe('Archived at or after this instant.');
});
