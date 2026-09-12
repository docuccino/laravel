<?php

declare(strict_types=1);

use Docuccino\Attributes\IgnoreParam;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Extensions\AttributeParametersExtension;
use Docuccino\Laravel\Extensions\IgnoredParametersExtension;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderUntypedFilterExtension;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\AppFilteredListController;
use Workbench\App\Http\Requests\FilterBoundsRequest;

/**
 * `query-builder.untyped-filter` says the document publishes a filter with no type at all, so it has to
 * read the parameter as it FINALLY stands (docs/design/defect-classes.md §"A diagnostic that asserts an
 * outcome it never reads"). A report the author cannot clear is the failure this guards.
 */
function untypedFilterChain(): string
{
    // Two custom filters on a body nothing can reduce to a column, so the integration types neither.
    return 'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
        ."AllowedFilter::custom('named', \\Workbench\\App\\Filters\\CompositeFilter::class), "
        ."AllowedFilter::custom('opaque', \\Workbench\\App\\Filters\\CompositeFilter::class),"
        .'])->paginate()';
}

/**
 * The parameters and reports the QB passes leave behind, with the layers between them in their real
 * order: parameter attributes behind the integration, then the two finalize passes, subtractive first.
 *
 * @param  list<object>  $attributes
 * @param  array<string, mixed>  $representation
 * @return array{0: array<string, array<string, mixed>>, 1: list<string>}
 */
function runUntypedFilterPasses(array $attributes = [], array $representation = []): array
{
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: new ActionRef('', 'App\\Gadgets', 'index'),
        attributes: new AttributeSet($attributes),
        engine: new StubTypeEngine(traces: ['App\\Gadgets::index' => TraceScript::forChain(untypedFilterChain())]),
        extensions: new ResolvedExtensions(typeToSchema: DefaultTypeMappers::all()),
        document: new DocumentConfig('default', [], representation: $representation),
    );

    $operation = new OperationDraft;
    (new QueryBuilderParametersExtension)->handle($operation, $context);
    (new AttributeParametersExtension)->handle($operation, $context);
    (new IgnoredParametersExtension)->handle($operation, $context);
    (new QueryBuilderUntypedFilterExtension)->handle($operation, $context);

    $byName = [];
    foreach ($operation->freeze()->parameters as $parameter) {
        $byName[$parameter->name] = $parameter->toArray();
    }

    $reported = array_map(
        static fn ($diagnostic): string => $diagnostic->message,
        diagnosticsCoded($context->components->diagnostics(), 'query-builder.untyped-filter'),
    );

    return [$byName, array_values($reported)];
}

it('reports the filter the document publishes untyped, and not the one an attribute typed', function (): void {
    [$byName, $reported] = runUntypedFilterPasses([
        new QueryParameter('filter[named]', type: 'string'),
    ]);

    // The attribute is the very fix the help asks for, so a report here is one no edit could clear.
    expect($byName['filter[named]']['schema']['type'])->toBe('string')
        ->and($byName['filter[named]']['schema']['x-docuccino']['provenance'][0]['producer'])->toBe('attribute')
        ->and($reported)->toHaveCount(1)
        ->and($reported[0])->toContain('"opaque"')
        ->and($byName['filter[opaque]']['schema'])->toBe([]);
});

it('reads the deepObject property, where that representation publishes the filter', function (): void {
    [$byName, $reported] = runUntypedFilterPasses(
        [new QueryParameter('filter[named]', type: 'string')],
        ['filters' => 'deepObject'],
    );

    $properties = $byName['filter']['schema']['properties'];

    // Both properties carry the integration's prose, so "says nothing about the value" is the question
    // — an empty-schema test would answer no for both and report neither.
    expect($properties['named']['type'])->toBe('string')
        ->and($properties['opaque'])->not->toHaveKey('type')
        ->and($properties['opaque']['description'])->not->toBeEmpty()
        ->and($reported)->toHaveCount(1)
        ->and($reported[0])->toContain('"opaque"');
});

it('says nothing about a filter #[IgnoreParam] dropped, which the document does not carry at all', function (): void {
    [$byName, $reported] = runUntypedFilterPasses([
        new IgnoreParam(name: 'filter[opaque]', in: 'query'),
    ]);

    // The one still published is still reported, so this is the removal doing it and not a pass that
    // stopped running.
    expect($byName)->not->toHaveKey('filter[opaque]')
        ->and($reported)->toHaveCount(1)
        ->and($reported[0])->toContain('"named"');
});

it('still reports every untyped filter when no other layer types any of them', function (): void {
    [, $reported] = runUntypedFilterPasses();

    expect($reported)->toHaveCount(2)
        ->and(implode(' ', $reported))->toContain('"named"')
        ->and(implode(' ', $reported))->toContain('"opaque"');
});

/**
 * The ad-hoc route both whole-build cases are made over: the filters are the application's own
 * closures, one typed by the action's attribute, one by the FormRequest recovered in the request
 * phase, one by nothing at all.
 */
function registerAppFilteredRoute(): void
{
    $action = AppFilteredListController::class.'::index';

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [
            // rules() as the engine recovers it — the constant array the class really returns.
            FilterBoundsRequest::class.'::rules' => new ActionAnalysis(returns: [new ReturnSite(
                new ArrayShapeT([new ArrayShapeField('filter.min_days', new LiteralT('integer|min:1|max:90'))]),
                new SourceLocation(''),
            )]),
        ],
        // Read out of the controller's real file rather than copied here: a chain written twice leaves
        // the suite green against the copy when somebody edits the application.
        traceOverrides: [$action => TraceScript::forMethod(
            (string) (new ReflectionClass(AppFilteredListController::class))->getFileName(),
            AppFilteredListController::class,
            'index',
            receiverFqcn: 'Spatie\\QueryBuilder\\QueryBuilder',
        )],
    ));

    /** @var Router $router */
    $router = app('router');
    $router->get('api/app-filtered', [AppFilteredListController::class, 'index']);
}

/**
 * What this route PUBLISHES — the three schemas and the one report, severity and help included — is
 * byte-locked in `workbench-app-filtered.uir.json` and its diagnostics pair, so it is not restated here.
 * What a route-restricted golden build cannot show is that the report does not LEAK: the default
 * workbench routes carry another Query Builder list with a free-text filter nothing types, and this pass
 * runs at Finalize over the whole document. So the one fact left is a full build's count for this route.
 */
it('reports the untyped filter to its own route, in a build carrying another route that has one', function (): void {
    registerAppFilteredRoute();

    $result = generateDocument();
    $all = diagnosticsCoded($result->diagnostics, 'query-builder.untyped-filter');
    $mine = array_values(array_filter(
        $all,
        static fn ($diagnostic): bool => str_contains((string) $diagnostic->routeSignature, 'api/app-filtered'),
    ));

    // Anti-vacuity: the other route really did report one too, or this proves no isolation at all.
    expect($all)->toHaveCount(2)
        ->and($mine)->toHaveCount(1)
        ->and($mine[0]->message)->toContain('"search"');
});

/**
 * The workbench's own Query Builder list, built as a whole document: one filter the application's
 * closure handles and nothing types, under whatever names the application configured.
 *
 * @return array{0: array<string, mixed>, 1: list<Diagnostic>}
 */
function ledgerQueryFilters(): array
{
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
    $result = generateDocument();

    /** @var list<array<string, mixed>> $parameters */
    $parameters = $result->document->toArray()['paths']['/api/ledger-query']['get']['parameters'] ?? [];
    $schemas = [];
    foreach ($parameters as $parameter) {
        $schemas[(string) $parameter['name']] = $parameter['schema'] ?? null;
    }

    $reports = array_values(array_filter(
        diagnosticsCoded($result->diagnostics, 'query-builder.untyped-filter'),
        static fn (Diagnostic $diagnostic): bool => str_contains((string) $diagnostic->routeSignature, 'api/ledger-query'),
    ));

    return [$schemas, $reports];
}

/**
 * The address is the whole mechanism: the pass that publishes the parameter and the pass that reports
 * on it are different passes, so a report derived from anything but what was published is a report
 * about a node nobody wrote — which finds nothing and says nothing, silently, on every route. Both
 * representations are pinned under a RENAMED parameter, because at the package's default names a
 * reporter that re-derived the address agrees with one that carries it.
 */
it('reports against the parameter name the application configured, not the package default', function (): void {
    config()->set('query-builder.parameters.filter', 'q');

    [$schemas, $reports] = ledgerQueryFilters();

    // Anti-vacuity: the renamed key is what reached the document, and it really is published untyped.
    expect($schemas)->toHaveKey('q[search]')
        ->and($schemas)->not->toHaveKey('filter[search]')
        ->and($schemas['q[search]'])->toBe([])
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]->message)->toContain('"search"');
});

it('reports against the renamed deepObject parameter, at the property the filter is published as', function (): void {
    config()->set('query-builder.parameters.filter', 'q');
    setBuild('documents.default.representation.filters', 'deepObject');

    [$schemas, $reports] = ledgerQueryFilters();

    expect($schemas)->toHaveKey('q')
        ->and($schemas['q']['properties']['search'])->not->toHaveKey('type')
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]->message)->toContain('"search"');
});

/**
 * The artifact this family had none of: the parameters three app-handled filters are published as, and
 * the reports the same build hands the author, in bytes. A change to what the report says, which filter
 * it fires on, or where it is addressed moves these files — so a claim that a rework changed nothing has
 * something to be false about. Restricted to the one route, so no committed golden churns and no
 * unrelated route can quiet the family by accident.
 */
function appFilteredDocument(): GenerationResult
{
    registerAppFilteredRoute();

    return generateDocument(static function (array $raw): array {
        $raw['info'] = ['title' => 'App-filtered API', 'version' => '1.0.0'];
        $raw['routes'] = ['include' => ['api/app-filtered']];

        return $raw;
    });
}

it('emits the app-handled filter document and its diagnostics byte-identically', function (): void {
    $result = appFilteredDocument();

    assertGolden('workbench-app-filtered.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden(
        'workbench-app-filtered.diagnostics.json',
        json_encode(diagnosticRecords($result->diagnostics), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );
});

/**
 * The same route under the other filter representation, which is the population the bracketed golden
 * above cannot stand in for: two producers describe one filter surface, and this is the document that
 * says whether they describe it once. What a consumer receives is one `filter` object — its members
 * typed by whichever layer typed them — and not that object plus a `filter[...]` parameter per member
 * restating the same value under a second identity.
 */
it('emits one deepObject container for the surface both producers describe, byte-identically', function (): void {
    setBuild('documents.default.representation.filters', 'deepObject');

    $result = appFilteredDocument();

    assertGolden('workbench-app-filtered-deep.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden(
        'workbench-app-filtered-deep.diagnostics.json',
        json_encode(diagnosticRecords($result->diagnostics), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );
});
