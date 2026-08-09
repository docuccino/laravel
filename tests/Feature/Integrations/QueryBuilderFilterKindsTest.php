<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Tests\Support\QbTraceScript;

/**
 * End-to-end proof of the QB filter-kind ENRICHMENT (round 2) in-process: a scripted trace over a real
 * workbench model (`Gadget`) drives the real extension, so each kind's value types off the model's
 * cast / scope signature / custom filter, and the partial-on-enum nudge fires — without the real
 * engine (that is proven behaviourally in the fixture group).
 */
function runFilterKinds(string $chain): array
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
    );

    $operation = new OperationDraft;
    (new QueryBuilderParametersExtension)->handle($operation, $context);

    $byName = [];
    foreach ($operation->freeze()->parameters as $parameter) {
        $byName[$parameter->name] = $parameter->toArray();
    }

    return [$byName, $context->components->diagnostics()];
}

$chain = 'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
    ."'name', "                                                                              // bare, uncast → plain string, no nudge
    ."'status', "                                                                            // bare, enum-cast → partial-on-enum nudge
    ."AllowedFilter::scope('minScore'), "                                                    // scope int value
    ."AllowedFilter::callback('active', function (\$q, \$value) { \$q->where('active', \$value); }), " // callback bool column
    ."AllowedFilter::operator('score', FilterOperator::EQUAL), "                             // static operator int
    ."AllowedFilter::custom('flag', \\Workbench\\App\\Filters\\DocumentedFilter::class), "   // custom attribute (int, example 42)
    ."AllowedFilter::custom('sc', \\Workbench\\App\\Filters\\ScoreFilter::class), "          // custom __invoke body (score int)
    .'AllowedFilter::trashed(),'                                                             // trashed with/only enum
    .'])->paginate()';

it('types each recovered filter kind off the model and applies the custom-filter attribute', function () use ($chain): void {
    [$byName] = runFilterKinds($chain);

    // Bare uncast filter → plain string, generic description.
    expect($byName['filter[name]']['schema']['type'])->toBe('string')
        ->and($byName['filter[name]']['schema'])->not->toHaveKey('enum')
        ->and($byName['filter[name]']['description'])->toBe('Partial-match filter');

    // Bare filter over an enum column is NOT enum-typed (partial), stays a string.
    expect($byName['filter[status]']['schema']['type'])->toBe('string')
        ->and($byName['filter[status]']['schema'])->not->toHaveKey('enum');

    // Scope value parameter (int) → integer.
    expect($byName['filter[minScore]']['schema']['type'])->toBe('integer');

    // Callback closure `where('active', $value)` → the model's boolean cast.
    expect($byName['filter[active]']['schema']['type'])->toBe('boolean');

    // Static operator → typed off the `score` integer cast.
    expect($byName['filter[score]']['schema']['type'])->toBe('integer');

    // Custom filter class #[QueryParameter] override: int type + description + example (body ignored).
    expect($byName['filter[flag]']['schema']['type'])->toBe('integer')
        ->and($byName['filter[flag]']['description'])->toBe('Minimum popularity score.')
        ->and($byName['filter[flag]']['example'])->toBe(42);

    // Custom filter class __invoke body `where('score', $value)` → the score integer cast.
    expect($byName['filter[sc]']['schema']['type'])->toBe('integer');

    // Trashed → fixed with/only enum.
    expect($byName['filter[trashed]']['schema']['enum'])->toBe(['with', 'only']);
});

it('emits a partial-on-enum info nudge for a partial filter over an enum column, and only for that filter', function () use ($chain): void {
    [, $diagnostics] = runFilterKinds($chain);

    $partialOnEnum = array_values(array_filter(
        $diagnostics,
        static fn ($d): bool => $d->code === 'query-builder.partial-on-enum',
    ));

    expect($partialOnEnum)->toHaveCount(1)
        ->and($partialOnEnum[0]->message)->toContain('status');
});

it('does not nudge when a partial filter targets a non-enum column', function (): void {
    [, $diagnostics] = runFilterKinds(
        "QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['name'])->paginate()",
    );

    $codes = array_map(static fn ($d): string => $d->code, $diagnostics);
    expect($codes)->not->toContain('query-builder.partial-on-enum');
});
