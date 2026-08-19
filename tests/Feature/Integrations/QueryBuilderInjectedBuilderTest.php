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
use Docuccino\Laravel\Routing\VendorRoutePolicy;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\GadgetListQuery;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\InjectedGadgetController;
use Docuccino\Laravel\Tests\Support\TraceScript;

/**
 * The action that is HANDED its builder: the container resolves a `QueryBuilder` subclass which configures
 * every allow-list in its own CONSTRUCTOR, and the action body is nothing but the terminal. No call in
 * that body leads to the configuration, so the extension traces the constructor as a root of its own —
 * after the action, into the same facts. In-process mechanics; the recovery half is proven on the real
 * engine in the fixture group.
 */
function injectedBuilderRun(string $actionChain, string $constructorChain, ?VendorRoutePolicy $vendor = null): array
{
    $engine = new StubTypeEngine(traces: [
        InjectedGadgetController::class.'::index' => TraceScript::forChain($actionChain, file: 'InjectedGadgetController.php'),
        GadgetListQuery::class.'::__construct' => TraceScript::forChain($constructorChain, file: 'GadgetListQuery.php'),
    ]);

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: new ActionRef(
            (string) (new ReflectionClass(InjectedGadgetController::class))->getFileName(),
            InjectedGadgetController::class,
            'index',
        ),
        attributes: new AttributeSet,
        engine: $engine,
        // The subclass's custom paginating terminal, declared the way a document declares it.
        document: new DocumentConfig('default', [], raw: [
            'integrations' => ['query_builder' => ['pagination_terminals' => ['paginateList']]],
        ]),
    );

    $operation = new OperationDraft;
    $extension = new QueryBuilderParametersExtension(isVendorFile: $vendor === null ? null : $vendor->isVendorFile(...));
    $extension->handle($operation, $context);

    $byName = [];
    foreach ($operation->freeze()->parameters as $parameter) {
        $byName[$parameter->name] = $parameter->toArray();
    }

    return [$byName, $context];
}

it('recovers the allow-lists a constructor-configured builder holds, and stops diagnosing silence', function (): void {
    [$byName, $context] = injectedBuilderRun(
        '$query->paginateList(25)',
        "\$this->allowedFilters([AllowedFilter::exact('status')])->allowedSorts(['score'])->defaultSort('score')",
    );

    expect(array_keys($byName))->toContain('filter[status]', 'sort', 'page');

    // The whole point: the allow-lists are no longer lost, so the honest "nothing recovered" info goes.
    $codes = array_map(static fn ($d): string => $d->code, $context->components->diagnostics());
    expect($codes)->not->toContain('query-builder.no-allowlists-recovered');
});

it('keeps the outermost call site\'s terminal, whatever a seeded root reaches', function (): void {
    // The constructor script deliberately reaches a paginating terminal of its own, renaming the key: if
    // the seeded roots were walked before the action, the document would publish the constructor's
    // `inner` instead of the action's `page`. Trace order is a determinism contract.
    [$byName] = injectedBuilderRun(
        '$query->paginateList(25)',
        "\$this->allowedFilters([AllowedFilter::exact('status')])->paginate(15, ['*'], 'inner')",
    );

    expect(array_keys($byName))->toContain('page')
        ->and(array_keys($byName))->not->toContain('inner');
});

it('records a seeded root\'s files as route dependencies, so a warm fragment cannot go stale', function (): void {
    // A seeded root goes through RouteContext::traceFrom(), which records its files. Without that,
    // editing the query class would leave the cached fragment warm and wrong.
    [, $context] = injectedBuilderRun(
        '$query->paginateList(25)',
        "\$this->allowedFilters([AllowedFilter::exact('status')])",
    );

    expect($context->dependencyFiles())->toContain((new ReflectionClass(GadgetListQuery::class))->getFileName());
});

it('refuses a builder subclass an installed package ships, seeding no vendor root', function (): void {
    // This is the one place a trace root is CHOSEN from a type hint, so it is the one place a vendor file
    // could become a root: the walk would spend the file budget there, harvest the package's own
    // allow-lists into the user's document, and record a vendor file as a fragment-cache dependency.
    $query = (string) (new ReflectionClass(GadgetListQuery::class))->getFileName();

    [$byName, $context] = injectedBuilderRun(
        '$query->paginateList(25)',
        "\$this->allowedFilters([AllowedFilter::exact('status')])",
        // The real route-exclusion boundary, pointed at the directory this fixture lives in — as though
        // the package had shipped the query class.
        new VendorRoutePolicy(dirname($query)),
    );

    $codes = array_map(static fn ($d): string => $d->code, $context->components->diagnostics());
    expect(array_keys($byName))->toBe(['page'])
        ->and($codes)->toContain('query-builder.no-allowlists-recovered')
        // Nothing about the refused root reaches the cache key either.
        ->and($context->dependencyFiles())->not->toContain($query);
});

it('keeps an app-located builder subclass, which the vendor boundary has nothing to say about', function (): void {
    // The mirror: the same fixture, the same policy, a vendor directory it simply does not live under.
    $query = (string) (new ReflectionClass(GadgetListQuery::class))->getFileName();

    [$byName, $context] = injectedBuilderRun(
        '$query->paginateList(25)',
        "\$this->allowedFilters([AllowedFilter::exact('status')])",
        new VendorRoutePolicy(dirname($query, 2).'/vendor'),
    );

    expect(array_keys($byName))->toContain('filter[status]')
        ->and($context->dependencyFiles())->toContain($query);
});

it('still diagnoses silence when the injected builder configures nothing the trace can see', function (): void {
    [$byName, $context] = injectedBuilderRun('$query->paginateList(25)', '$this->getEloquentBuilder()');

    $codes = array_map(static fn ($d): string => $d->code, $context->components->diagnostics());
    expect(array_keys($byName))->toBe(['page'])
        ->and($codes)->toContain('query-builder.no-allowlists-recovered');
});
