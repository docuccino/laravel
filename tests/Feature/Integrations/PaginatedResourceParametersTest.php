<?php

declare(strict_types=1);

use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Extensions\AttributeParametersExtension;
use Docuccino\Laravel\Integrations\ApiResources\PaginatedResourceParametersExtension;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Registry\ExtensionRegistry;
use Docuccino\Laravel\Tests\Support\QbTraceScript;

/**
 * The request half of a paginated resource collection, in-process over REAL Laravel paginator chains
 * parsed from source: the terminal decides which key the endpoint reads, and nothing but that key is
 * claimed. The real engine proves the same terminals in the fixture group.
 *
 * @param  list<object>  $attributes
 * @param  list<OperationExtension>  $before  parameter producers that run ahead of this one
 * @param  array<string, mixed>  $integrations  the document's `integrations` bag
 * @param  DType|null  $returns  the action's return type (a resource collection by default)
 * @return array<string, array<string, mixed>>
 */
function runPaginatedResourceParameters(
    string $chain,
    string $receiver = 'Illuminate\\Database\\Eloquent\\Builder',
    array $attributes = [],
    array $before = [],
    array $integrations = [],
    ?DType $returns = null,
): array {
    $returns ??= new ClassT(
        'Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection',
        [new ClassT('Docuccino\\Laravel\\Tests\\Fixtures\\ApiResources\\ArticleResource')],
    );

    $engine = new StubTypeEngine(
        analyses: ['App\\Articles::index' => new ActionAnalysis(
            returns: [new ReturnSite($returns, new SourceLocation(''))],
        )],
        traces: ['App\\Articles::index' => QbTraceScript::forChain($chain, $receiver)],
    );

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/articles'),
        actionRef: new ActionRef('', 'App\\Articles', 'index'),
        attributes: new AttributeSet($attributes),
        engine: $engine,
        document: new DocumentConfig('default', [], raw: ['integrations' => $integrations]),
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
        ),
    );

    $operation = new OperationDraft;
    foreach ([...$before, new PaginatedResourceParametersExtension] as $extension) {
        $extension->handle($operation, $context);
    }

    $byName = [];
    foreach ($operation->freeze()->parameters as $parameter) {
        $byName[$parameter->name] = $parameter->toArray();
    }

    return $byName;
}

/**
 * A parameter's schema keywords, without the provenance record the draft attaches — so a test can pin
 * the exact keywords, and {@see paginatedResourceLayer()} pins the layer that won them.
 *
 * @param  array<string, mixed>  $parameter
 * @return array<string, mixed>
 */
function paginatedResourceSchema(array $parameter): array
{
    $schema = $parameter['schema'] ?? null;
    if (! is_array($schema)) {
        return [];
    }

    unset($schema['x-docuccino']);

    return $schema;
}

/**
 * The precedence layer that won a parameter's schema.
 *
 * @param  array<string, mixed>  $parameter
 */
function paginatedResourceLayer(array $parameter): ?string
{
    $layer = $parameter['schema']['x-docuccino']['provenance'][0]['layer'] ?? null;

    return is_string($layer) ? $layer : null;
}

it('runs after every other parameter producer, so it can see what they contributed', function (): void {
    $document = new DocumentConfig('default', []);
    $resolved = app(ExtensionRegistry::class)->resolve(app(), DefaultExtensions::all($document), []);

    $parameterPhase = array_values(array_filter(
        $resolved->operationExtensions,
        static fn (OperationExtension $extension): bool => $extension->phase() === OperationPhase::Parameters,
    ));

    expect(end($parameterPhase))->toBeInstanceOf(PaginatedResourceParametersExtension::class);
});

it('documents the key each paginator kind reads, and nothing else', function (string $terminal, string $name, array $schema): void {
    $params = runPaginatedResourceParameters('$q->'.$terminal.'(15)');

    expect(array_keys($params))->toBe([$name])
        ->and($params[$name]['in'])->toBe('query')
        ->and($params[$name]['required'])->toBeFalse()
        ->and(paginatedResourceSchema($params[$name]))->toBe($schema)
        // Inference-grade knowledge about the framework, so a docblock/attribute/overlay/config still wins.
        ->and(paginatedResourceLayer($params[$name]))->toBe('integration');
})->with([
    // paginate()/simplePaginate() read `page` (Paginator::resolveCurrentPage), cursorPaginate() reads
    // `cursor` (CursorPaginator::resolveCurrentCursor).
    'paginate → page' => ['paginate', 'page', ['type' => 'integer', 'default' => 1, 'minimum' => 1]],
    'simplePaginate → page' => ['simplePaginate', 'page', ['type' => 'integer', 'default' => 1, 'minimum' => 1]],
    'cursorPaginate → cursor' => ['cursorPaginate', 'cursor', ['type' => 'string']],
]);

it('describes the key for whoever reads the document, not for the code that produced it', function (): void {
    $params = runPaginatedResourceParameters('$q->paginate(15)');

    expect($params['page']['description'])->toBe('Page number.');
});

it('never claims per_page, which paginate() takes from the call site and not from the request', function (string $chain): void {
    expect(runPaginatedResourceParameters($chain))->not->toHaveKey('per_page');
})->with([
    'a bare call' => ['$q->paginate()'],
    'a literal size' => ['$q->paginate(15)'],
    'a cursor page' => ['$q->cursorPaginate(15)'],
]);

it('contributes nothing when the collection was never paginated', function (): void {
    expect(runPaginatedResourceParameters('$q->get()'))->toBe([]);
});

it('contributes nothing when the paginated result is not what the action answers with', function (): void {
    // A paginating call somewhere in the body is not a paginated response. The key is documented for the
    // endpoint whose body Docuccino already wrapped in the paginator envelope, and no other.
    $params = runPaginatedResourceParameters(
        '$q->paginate(15)',
        returns: new ClassT('Illuminate\\Http\\RedirectResponse'),
    );

    expect($params)->toBe([]);
});

it('honours a page key the call site renamed', function (string $chain, string $name): void {
    expect(array_keys(runPaginatedResourceParameters($chain)))->toBe([$name]);
})->with([
    // paginate($perPage, $columns, $pageName) / cursorPaginate($perPage, $columns, $cursorName): each
    // terminal's argument, positionally and by name.
    'positional pageName' => ["\$q->paginate(15, ['*'], 'p')", 'p'],
    'positional cursorName' => ["\$q->cursorPaginate(15, ['*'], 'after')", 'after'],
    'named pageName on paginate' => ["\$q->paginate(perPage: 15, pageName: 'p')", 'p'],
    'named pageName on simplePaginate' => ["\$q->simplePaginate(perPage: 15, pageName: 'p')", 'p'],
    'named cursorName' => ["\$q->cursorPaginate(perPage: 15, cursorName: 'after')", 'after'],
]);

it('withholds the key entirely when the call site renamed it to something unreadable', function (): void {
    // A guessed `page` here would name a key the endpoint does not read — worse than saying nothing.
    expect(runPaginatedResourceParameters("\$q->paginate(15, ['*'], \$pageName)"))->toBe([]);
});

it('keeps the default name for a custom terminal, which forwards to paginate($perPage)', function (): void {
    $params = runPaginatedResourceParameters(
        '$q->paginateList(20)',
        integrations: ['query_builder' => ['pagination_terminals' => ['paginateList']]],
    );

    expect(array_keys($params))->toBe(['page']);
});

it('adds no second page when the Query Builder integration already documented one', function (): void {
    $params = runPaginatedResourceParameters(
        "QueryBuilder::for(\\Workbench\\App\\Models\\Form::class)->allowedFilters(['name'])->paginate(20)",
        receiver: 'Spatie\\QueryBuilder\\QueryBuilder',
        before: [new QueryBuilderParametersExtension],
    );

    // One `page`, and it is the Query Builder's.
    expect(array_keys($params))->toBe(['filter[name]', 'page'])
        ->and(paginatedResourceSchema($params['page']))->toBe(['type' => 'integer', 'default' => 1, 'minimum' => 1])
        ->and($params['page']['description'])->toBe('Page number.');
});

it('agrees with the Query Builder on a page key the call site renamed', function (): void {
    // Both producers read the same terminal's `pageName` argument, so the operation names the one key
    // this endpoint really reads — once. A producer that ignored the argument would publish `page` here.
    $params = runPaginatedResourceParameters(
        "QueryBuilder::for(\\Workbench\\App\\Models\\Form::class)->allowedFilters(['name'])->paginate(20, ['*'], 'p')",
        receiver: 'Spatie\\QueryBuilder\\QueryBuilder',
        before: [new QueryBuilderParametersExtension],
    );

    expect(array_keys($params))->toBe(['filter[name]', 'p']);
});

it('names no page key at all when the Query Builder could not fold the one the chain renamed', function (): void {
    // Neither producer may guess here, and the pair must not disagree either: the Query Builder
    // withholding the key is not licence for this one to fill the gap with a `page` nothing reads.
    $params = runPaginatedResourceParameters(
        "QueryBuilder::for(\\Workbench\\App\\Models\\Form::class)->allowedFilters(['name'])->paginate(20, ['*'], \$pageName)",
        receiver: 'Spatie\\QueryBuilder\\QueryBuilder',
        before: [new QueryBuilderParametersExtension],
    );

    expect(array_keys($params))->toBe(['filter[name]']);
});

it('stays quiet where the author pinned the framework default and the chain renamed it', function (): void {
    // The author's key and the chain's disagree, and the author's wins — the layer above says so, and a
    // second selector beside it could only contradict it. Nothing here re-states the chain's `p`.
    $params = runPaginatedResourceParameters(
        "\$q->paginate(15, ['*'], 'p')",
        attributes: [new QueryParameter('page', type: 'int', description: 'Page number.')],
        before: [new AttributeParametersExtension],
    );

    expect(array_keys($params))->toBe(['page']);
});

it('leaves a page key the author pinned exactly as they wrote it', function (): void {
    $params = runPaginatedResourceParameters(
        '$q->paginate(15)',
        attributes: [new QueryParameter('page', type: 'string', description: 'An opaque page token.')],
        before: [new AttributeParametersExtension],
    );

    expect(array_keys($params))->toBe(['page'])
        ->and($params['page']['description'])->toBe('An opaque page token.')
        ->and(paginatedResourceSchema($params['page']))->toBe(['type' => 'string'])
        ->and(paginatedResourceLayer($params['page']))->toBe('attribute');
});
