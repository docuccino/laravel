<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Laravel\Integrations\QueryBuilder\QbEntry;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderTraceVisitor;
use Docuccino\Laravel\Tests\Support\StubTraceScope;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * In-process proof of the QB trace visitor's harvest half over REAL php-parser nodes (a stub
 * TypeScope stands in for the engine). Complements the real-engine crown-jewel test (fixture group)
 * that proves recovery through a two-deep helper — this covers the allow-list variants (factory
 * descriptors, aggregate includes, sparse fields) and the unresolved-degradation contract the spike
 * fixture does not exercise.
 */
function traceQbSnippet(string $chain, array $customTerminals = ['paginateList']): QueryBuilderTraceVisitor
{
    $code = "<?php\n\$q = ".$chain.";\n";
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($code) ?? [];

    $visitor = new QueryBuilderTraceVisitor(customTerminals: $customTerminals);
    $scope = new StubTraceScope(new ClassT('Spatie\\QueryBuilder\\QueryBuilder'));

    $traverser = new NodeTraverser(new class($visitor, $scope) extends NodeVisitorAbstract
    {
        public function __construct(private $qb, private $scope) {}

        public function enterNode(Node $node)
        {
            if ($node instanceof Node\Expr) {
                $this->qb->enterNode($node, $this->scope);
            }

            return null;
        }
    });
    $traverser->traverse($ast);

    return $visitor;
}

/**
 * @return list<array{string, string}>
 */
function entryPairs(array $entries): array
{
    return array_map(static fn (QbEntry $e): array => [$e->name, $e->kind], $entries);
}

it('recovers allowedFilters as strings and factory descriptors with their kind', function (): void {
    $facts = traceQbSnippet(
        "ListQueryBuilder::for(User::class)->allowedFilters(['name', AllowedFilter::exact('status'), AllowedFilter::partial('email'), AllowedFilter::scope('active')])"
    )->facts;

    expect(entryPairs($facts->filters))->toBe([
        ['name', 'default'],
        ['status', 'exact'],
        ['email', 'partial'],
        ['active', 'scope'],
    ]);
});

it('recovers allowedSorts (incl. AllowedSort::field) and defaultSort', function (): void {
    $facts = traceQbSnippet(
        "QueryBuilder::for(User::class)->allowedSorts(['name', AllowedSort::field('created_at')])->defaultSort('name')"
    )->facts;

    expect(entryPairs($facts->sorts))->toBe([['name', 'default'], ['created_at', 'field']])
        ->and($facts->defaultSorts)->toBe(['name']);
});

it('recovers allowedIncludes incl. count/exists and QB v7 aggregate variants', function (): void {
    $facts = traceQbSnippet(
        "QueryBuilder::for(User::class)->allowedIncludes(['author', AllowedInclude::count('commentsCount'), AllowedInclude::exists('publishedExists'), AllowedInclude::aggregate('rating', 'avg')])"
    )->facts;

    expect(entryPairs($facts->includes))->toBe([
        ['author', 'default'],
        ['commentsCount', 'count'],
        ['publishedExists', 'exists'],
        ['rating', 'aggregate'],
    ]);
});

it('recovers allowedFields as type.field paths', function (): void {
    $facts = traceQbSnippet(
        "QueryBuilder::for(User::class)->allowedFields(['articles.title', 'articles.body', 'author.name'])"
    )->facts;

    expect(array_map(static fn (QbEntry $e): string => $e->name, $facts->fields))
        ->toBe(['articles.title', 'articles.body', 'author.name']);
});

it('detects the paginating terminal kind and outermost per-page', function (string $chain, string $kind, ?int $perPage): void {
    $facts = traceQbSnippet($chain)->facts;

    expect($facts->paginates)->toBeTrue()
        ->and($facts->paginationKind)->toBe($kind)
        ->and($facts->perPage)->toBe($perPage);
})->with([
    'length-aware with per-page' => ['QueryBuilder::for(User::class)->paginate(25)', 'length', 25],
    'simple' => ['QueryBuilder::for(User::class)->simplePaginate()', 'simple', null],
    'cursor' => ['QueryBuilder::for(User::class)->cursorPaginate(50)', 'cursor', 50],
    'custom terminal (length)' => ['QueryBuilder::for(User::class)->paginateList(15)', 'length', 15],
]);

it('records a diagnostic-bound unresolved entry for a non-constant filter, never silently dropping it', function (): void {
    $facts = traceQbSnippet(
        "QueryBuilder::for(User::class)->allowedFilters(['name', AllowedFilter::exact(\$dynamic)])"
    )->facts;

    // The literal survives; the dynamic descriptor argument degrades to an unresolved note.
    expect(entryPairs($facts->filters))->toBe([['name', 'default']])
        ->and($facts->unresolved)->toHaveCount(1)
        ->and($facts->unresolved[0])->toContain('allowedFilters entry at test.php:');
});

it('recovers the subject model from for(Model::class)', function (): void {
    $facts = traceQbSnippet(
        "QueryBuilder::for(App\\Models\\User::class)->allowedFilters(['name'])"
    )->facts;

    expect($facts->subjectModel)->toBe('App\\Models\\User');
});

it('leaves the subject model null when for() is not reached', function (): void {
    // A bare allowedFilters chain with no for() origin in the snippet.
    $facts = traceQbSnippet("\$builder->allowedFilters(['name'])")->facts;

    expect($facts->subjectModel)->toBeNull();
});

it('recovers the internal column name from the second factory argument', function (): void {
    $facts = traceQbSnippet(
        "QueryBuilder::for(App\\Models\\User::class)->allowedFilters([AllowedFilter::exact('status', 'status_code')])"
    )->facts;

    expect($facts->filters[0]->name)->toBe('status')
        ->and($facts->filters[0]->internal)->toBe('status_code')
        ->and($facts->filters[0]->column())->toBe('status_code');
});

it('recovers a constant ->default() modifier and a ->nullable() modifier', function (): void {
    $facts = traceQbSnippet(
        "QueryBuilder::for(App\\Models\\User::class)->allowedFilters([AllowedFilter::exact('status')->default('published')->nullable()])"
    )->facts;

    $filter = $facts->filters[0];
    expect($filter->name)->toBe('status')
        ->and($filter->kind)->toBe('exact')
        ->and($filter->hasDefault)->toBeTrue()
        ->and($filter->default)->toBe('published')
        ->and($filter->nullable)->toBeTrue();
});

it('degrades a non-constant ->default() to no default without dropping the entry', function (): void {
    $facts = traceQbSnippet(
        "QueryBuilder::for(App\\Models\\User::class)->allowedFilters([AllowedFilter::exact('status')->default(\$dynamic)])"
    )->facts;

    expect($facts->filters)->toHaveCount(1)
        ->and($facts->filters[0]->name)->toBe('status')
        ->and($facts->filters[0]->hasDefault)->toBeFalse()
        ->and($facts->unresolved)->toBe([]);
});

it('attributes a comment directly above an allow-list entry as its description', function (): void {
    $chain = "QueryBuilder::for(App\\Models\\User::class)->allowedFilters([\n"
        ."    // The lifecycle status of the record.\n"
        ."    AllowedFilter::exact('status'),\n"
        ."    AllowedFilter::partial('email'),\n"
        .'])';

    $facts = traceQbSnippet($chain)->facts;

    expect($facts->filters[0]->comment)->toBe('The lifecycle status of the record.')
        ->and($facts->filters[1]->comment)->toBeNull();
});

it('marks a static operator filter to type off its internal column, and leaves a non-static one untyped', function (string $chain, ?string $typeColumn): void {
    $facts = traceQbSnippet($chain)->facts;

    expect($facts->filters[0]->kind)->toBe('operator')
        ->and($facts->filters[0]->typeColumn)->toBe($typeColumn);
})->with([
    'EQUAL is static → typed off the name' => ["QueryBuilder::for(App\\Models\\User::class)->allowedFilters([AllowedFilter::operator('score', FilterOperator::EQUAL)])", 'score'],
    'DYNAMIC is static → typed' => ["QueryBuilder::for(App\\Models\\User::class)->allowedFilters([AllowedFilter::operator('score', FilterOperator::DYNAMIC)])", 'score'],
    'GREATER_THAN is not static → string' => ["QueryBuilder::for(App\\Models\\User::class)->allowedFilters([AllowedFilter::operator('score', FilterOperator::GREATER_THAN)])", null],
]);

it('reads the operator internal-column name from the fourth factory argument', function (): void {
    $facts = traceQbSnippet(
        "QueryBuilder::for(App\\Models\\User::class)->allowedFilters([AllowedFilter::operator('score', FilterOperator::EQUAL, 'and', 'score_cents')])"
    )->facts;

    expect($facts->filters[0]->internal)->toBe('score_cents')
        ->and($facts->filters[0]->typeColumn)->toBe('score_cents');
});

it('recovers a callback filter\'s where column from its inline closure, and bails on a complex body', function (string $chain, string $kind, ?string $typeColumn): void {
    $facts = traceQbSnippet($chain)->facts;

    expect($facts->filters[0]->kind)->toBe($kind)
        ->and($facts->filters[0]->typeColumn)->toBe($typeColumn);
})->with([
    'closure where' => ["QueryBuilder::for(App\\Models\\User::class)->allowedFilters([AllowedFilter::callback('active', function (\$q, \$value) { \$q->where('is_active', \$value); })])", 'callback', 'is_active'],
    'arrow where' => ["QueryBuilder::for(App\\Models\\User::class)->allowedFilters([AllowedFilter::callback('active', fn (\$q, \$value) => \$q->where('is_active', \$value))])", 'callback', 'is_active'],
    'complex closure bails' => ["QueryBuilder::for(App\\Models\\User::class)->allowedFilters([AllowedFilter::callback('active', function (\$q, \$value) { \$q->where('a', \$value); \$q->orWhere('b', \$value); })])", 'callback', null],
]);

it('recovers a custom filter class FQCN from the F::class form', function (): void {
    $facts = traceQbSnippet(
        "QueryBuilder::for(App\\Models\\User::class)->allowedFilters([AllowedFilter::custom('flag', App\\Filters\\FlagFilter::class)])"
    )->facts;

    expect($facts->filters[0]->kind)->toBe('custom')
        ->and($facts->filters[0]->filterClass)->toBe('App\\Filters\\FlagFilter');
});

it('recovers the trashed filter, defaulting its name when called with no argument', function (): void {
    $facts = traceQbSnippet(
        'QueryBuilder::for(App\\Models\\User::class)->allowedFilters([AllowedFilter::trashed()])'
    )->facts;

    expect($facts->filters[0]->name)->toBe('trashed')
        ->and($facts->filters[0]->kind)->toBe('trashed');
});

it('attributes a comment directly above a bare-string filter entry as its description', function (): void {
    $chain = "QueryBuilder::for(App\\Models\\User::class)->allowedFilters([\n"
        ."    // The applicant's full name.\n"
        ."    'name',\n"
        .'])';

    $facts = traceQbSnippet($chain)->facts;

    expect($facts->filters[0]->name)->toBe('name')
        ->and($facts->filters[0]->kind)->toBe('default')
        ->and($facts->filters[0]->comment)->toBe("The applicant's full name.");
});

it('recovers a full chain built through a helper (all allow-lists + pagination together)', function (): void {
    $facts = traceQbSnippet(
        'QueryBuilder::for(User::class)'
        ."->allowedFilters(['name'])"
        ."->allowedSorts(['name'])"
        ."->allowedIncludes(['author'])"
        ."->allowedFields(['articles.title'])"
        .'->paginate()'
    )->facts;

    expect($facts->isEmpty())->toBeFalse()
        ->and($facts->filters)->toHaveCount(1)
        ->and($facts->sorts)->toHaveCount(1)
        ->and($facts->includes)->toHaveCount(1)
        ->and($facts->fields)->toHaveCount(1)
        ->and($facts->paginates)->toBeTrue();
});

it('pins the Tier-B degradations: spread method-call arg is unresolved, factory wrapper recovers name only', function (): void {
    // (a) `->allowedFilters(...$this->allowedFilters())` — a spread of a method call cannot be folded
    // (the method body is not traced across the engine-scope boundary), so it degrades to an
    // unresolved entry rather than silently vanishing. (Deferred: folding const-array method returns.)
    $splat = traceQbSnippet('ListQueryBuilder::for(User::class)->allowedFilters(...$this->allowedFilters())')->facts;
    expect($splat->filters)->toBe([])
        ->and($splat->unresolved)->not->toBe([]);

    // (b) a `ListFilters`-style factory wrapper folds to a descriptor: the parameter NAME recovers, but
    // the kind is the raw factory method (`enum`) — NOT peeled to the underlying AllowedFilter, so it
    // is not enum-typed. QueryBuilderParameters renders an unknown kind as a generic filter.
    $factory = traceQbSnippet("ListQueryBuilder::for(User::class)->allowedFilters([ListFilters::enum('status')])")->facts;
    expect(entryPairs($factory->filters))->toBe([['status', 'enum']]);
});
