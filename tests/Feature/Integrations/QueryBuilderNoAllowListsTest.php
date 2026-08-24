<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Tests\Support\TraceScript;

/**
 * The silence kill: when a paginating QB terminal is reached and NOTHING ELSE is (the descent could not
 * follow the chain), an info `query-builder.no-allowlists-recovered` names the action so the loss is never
 * silent. It is suppressed the moment any entry is recovered (or attempted-but-unresolved), and equally by
 * a `defaultSort()` — a chain call, so it proves the descent reached where an allow-list would sit, which
 * makes empty allow-lists the endpoint's truth rather than a recovery miss.
 */
function noAllowListDiagnostics(string $chain): array
{
    $engine = new StubTypeEngine(traces: [
        'App\\Widgets::index' => TraceScript::forChain(
            $chain,
            // A `$request` beside the builder, so a chain reading its page size off one is followed.
            variableTypes: ['request' => new ClassT('Illuminate\\Http\\Request')],
        ),
    ]);

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/widgets'),
        actionRef: new ActionRef('', 'App\\Widgets', 'index'),
        attributes: new AttributeSet,
        engine: $engine,
        document: new DocumentConfig('default', []),
    );

    (new QueryBuilderParametersExtension)->handle(new OperationDraft, $context);

    return array_values(array_filter(
        $context->components->diagnostics(),
        static fn ($d): bool => $d->code === 'query-builder.no-allowlists-recovered',
    ));
}

it('emits the diagnostic when a paginating terminal recovers no allow-lists', function (): void {
    $diagnostics = noAllowListDiagnostics('QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->paginate()');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->message)->toContain('App\\Widgets::index');
});

it('emits it for a bare terminal, whatever the terminal itself carried', function (string $chain): void {
    expect(noAllowListDiagnostics($chain))->toHaveCount(1);
})->with([
    // The subject model is the chain's ORIGIN and the terminal's arguments are the call the walk started
    // from: neither says the descent got as far as the configuration, so neither buys silence.
    'no subject model' => ['$q->paginate()'],
    'a folded page size' => ['QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->paginate(25)'],
    'a named page key' => ["QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->paginate(perPage: 25, pageName: 'p')"],
    // A page size read off the request is recovered from the terminal's own argument too, so it says
    // nothing about the chain — and a builder behind an indirection the trace cannot follow is exactly
    // the loss this diagnostic exists to name.
    'a page size read off the request' => ['QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->paginate($request->integer(\'per_page\', 15))'],
]);

it('stays silent once anything else about the builder was recovered', function (string $chain): void {
    expect(noAllowListDiagnostics($chain))->toBe([]);
})->with([
    // An allow-list, recovered or attempted: the original suppression.
    'a filter' => ['QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters([\'name\'])->paginate()'],
    'a sort' => ['QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedSorts([\'score\'])->paginate()'],
    'an include' => ['QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedIncludes([\'parts\'])->paginate()'],
    'a field' => ['QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFields([\'gadgets.name\'])->paginate()'],
    'an unresolved entry' => ['QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters([$dynamic])->paginate()'],
    // A chain call that is not an allow-list, which is the case this endpoint's author cannot act on:
    // the chain WAS reached, and it genuinely offers no filters or sorts.
    'a default sort' => ['QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->defaultSort(\'-created_at\')->paginate()'],
]);

/**
 * Silence has to come from the recovery it claims, not from a terminal the trace stopped seeing: a
 * regression that recorded no pagination at all would leave every silent row above green.
 */
it('proves the silent rows reached a paginating terminal', function (): void {
    $engine = new StubTypeEngine(traces: [
        'App\\Widgets::index' => TraceScript::forChain(
            'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->defaultSort(\'-created_at\')->paginate()',
        ),
    ]);

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/widgets'),
        actionRef: new ActionRef('', 'App\\Widgets', 'index'),
        attributes: new AttributeSet,
        engine: $engine,
        document: new DocumentConfig('default', []),
    );

    $operation = new OperationDraft;
    (new QueryBuilderParametersExtension)->handle($operation, $context);

    $names = array_map(static fn ($p): string => $p->name, $operation->freeze()->parameters);

    // `page` is the tell: the terminal was reached. A `defaultSort` with no `allowedSorts` publishes no
    // `sort` parameter of its own — there is no allow-list to sort by — so pagination is what proves it.
    expect($names)->toContain('page');
});
