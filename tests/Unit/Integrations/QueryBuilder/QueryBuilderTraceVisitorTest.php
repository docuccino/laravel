<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\FoldsCallReturns;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Core\Provenance\SourcePathResolver;
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
function traceQbSnippet(
    string $chain,
    array $customTerminals = ['paginateList'],
    array $foldedReturns = [],
    ?QueryBuilderTraceVisitor $visitor = null,
    ?ClassT $receiverType = null,
    ?TypeScope $scope = null,
): QueryBuilderTraceVisitor {
    $code = "<?php\n\$q = ".$chain.";\n";
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse($code) ?? [];

    $visitor ??= new QueryBuilderTraceVisitor(customTerminals: $customTerminals);
    $scope ??= new StubTraceScope($receiverType ?? new ClassT('Spatie\\QueryBuilder\\QueryBuilder'), $foldedReturns);

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
    // The engine answers deferred return folds once the walk is over; so does the stub. A scope that
    // answered eagerly has nothing left to drain.
    if ($scope instanceof StubTraceScope) {
        $scope->drainReturnFolds();
    }

    return $visitor;
}

/**
 * What the engine hands back for one folded return: the folded value plus the returned expression itself
 * (AST-only, since it belongs to the callee's file). Folded through the same stub scope the visitor sees, so
 * the fixture reads like the real answer.
 *
 * @return array{0: ?ConstValue, 1: ?Node\Expr}
 */
function qbFoldOf(string $expression): array
{
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$expression.';') ?? [];
    $statement = $ast[0] ?? null;
    $expr = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;

    return $expr === null
        ? [null, null]
        : [(new StubTraceScope(new ClassT('Spatie\\QueryBuilder\\QueryBuilder')))->constantValueOf($expr), $expr];
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

it('detects the paginating terminal kind and folds the outermost call\'s arguments', function (string $chain, string $kind, string $terminal, ?array $args): void {
    $facts = traceQbSnippet($chain)->facts;

    expect($facts->paginates)->toBeTrue()
        ->and($facts->paginationKind)->toBe($kind)
        ->and($facts->paginationTerminal)->toBe($terminal)
        ->and($facts->paginationArgs)->toBe($args);
})->with([
    'length-aware with per-page' => ['QueryBuilder::for(User::class)->paginate(25)', 'length', 'paginate', [0 => 25]],
    'simple' => ['QueryBuilder::for(User::class)->simplePaginate()', 'simple', 'simplePaginate', []],
    'cursor' => ['QueryBuilder::for(User::class)->cursorPaginate(50)', 'cursor', 'cursorPaginate', [0 => 50]],
    'custom terminal (length)' => ['QueryBuilder::for(User::class)->paginateList(15)', 'length', 'paginateList', [0 => 15]],
    // The page-name argument, which decides the key the endpoint really reads.
    'a renamed page key, positionally' => [
        "QueryBuilder::for(User::class)->paginate(25, ['*'], 'p')", 'length', 'paginate', [0 => 25, 1 => null, 2 => 'p'],
    ],
    'a renamed cursor key, by name' => [
        "QueryBuilder::for(User::class)->cursorPaginate(cursorName: 'after')", 'cursor', 'cursorPaginate', ['cursorName' => 'after'],
    ],
    // Written but unfoldable: recorded as null, so the parameter builder can tell it from absent.
    'an unfoldable page name' => [
        'QueryBuilder::for(User::class)->paginate(25, [\'*\'], $pageName)', 'length', 'paginate', [0 => 25, 1 => null, 2 => null],
    ],
    // A spread has no position of its own — it fills its index and every later one, so no argument is
    // where it looks, and the whole list is unindexable rather than partly absent.
    'a spread' => ['QueryBuilder::for(User::class)->paginate(...$args)', 'length', 'paginate', null],
    // A name past an unreadable spread is unknowable too: unpacking a keyed array binds parameters BY
    // name, so the sequence may already have filled the one written here.
    'a name after a spread' => [
        "QueryBuilder::for(User::class)->paginate(...\$args, pageName: 'p')", 'length', 'paginate', null,
    ],
    // Except where the call site wrote the sequence out: those items ARE the arguments, at the positions
    // they take, so the page key in there is read rather than widened away.
    'a spread the call site wrote out' => [
        "QueryBuilder::for(User::class)->paginate(25, ...[['*'], 'p'])", 'length', 'paginate', [0 => 25, 1 => null, 2 => 'p'],
    ],
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

it('recovers the subject model from a builder subclass\' parent::__construct', function (): void {
    // A subclass that IS the builder configures itself in its own constructor and never writes a `for()`:
    // the subject is the `Builder<Model>` its parent constructor is handed.
    $facts = traceQbSnippet(
        "parent::__construct(\\Workbench\\App\\Models\\Gadget::query()); \$this->allowedFilters(['name'])",
        receiverType: new ClassT('Spatie\\QueryBuilder\\QueryBuilder', [new ClassT('Workbench\\App\\Models\\Gadget')]),
    )->facts;

    expect($facts->subjectModel)->toBe('Workbench\\App\\Models\\Gadget')
        ->and($facts->filters)->toHaveCount(1);
});

it('ignores a __construct call that is not the parent\'s', function (): void {
    // Same argument, same types — only the `parent::` is gone, and with it any claim about the subject.
    $facts = traceQbSnippet(
        "self::__construct(\\Workbench\\App\\Models\\Gadget::query()); \$this->allowedFilters(['name'])",
        receiverType: new ClassT('Spatie\\QueryBuilder\\QueryBuilder', [new ClassT('Workbench\\App\\Models\\Gadget')]),
    )->facts;

    expect($facts->subjectModel)->toBeNull()
        ->and($facts->filters)->toHaveCount(1);
});

it('harvests a call site two trace roots both reach only once', function (): void {
    // Each root walks with its own memo, so a configure helper the action's walk AND a seeded constructor
    // root's walk both reach is visited twice. Harvesting it twice would document every entry twice — and
    // diagnose every unresolvable one twice.
    $chain = "ListQueryBuilder::for(App\\Models\\User::class)->allowedFilters(['name', AllowedFilter::exact(\$dynamic)])";

    $visitor = traceQbSnippet($chain);
    traceQbSnippet($chain, visitor: $visitor);

    expect(entryPairs($visitor->facts->filters))->toBe([['name', 'default']])
        ->and($visitor->facts->unresolved)->toHaveCount(1);
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

it('records a first-class-callable modifier as unresolved rather than peeling past it', function (): void {
    // `->default(...)` makes the whole entry a closure, not a configured filter. Peeling it would document
    // a filter the code never registers — and php-parser asserts on getArgs() for a first-class callable,
    // an AssertionError the engine swallows into a silently truncated trace.
    $facts = traceQbSnippet(
        "QueryBuilder::for(App\\Models\\User::class)->allowedFilters([AllowedFilter::exact('status')->default(...)])"
    )->facts;

    expect($facts->filters)->toBe([])
        ->and($facts->unresolved)->toHaveCount(1)
        ->and($facts->unresolved[0])->toContain('allowedFilters entry at test.php:');
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

it('recovers an entry the call site cannot name from the value its method returns', function (): void {
    // Nothing about `$this->termFilter()` names a filter, so the visitor defers it and the engine answers
    // with what the method returns. A written-argument fold still wins where there is one: the
    // `ListFilters`-style wrapper below keeps its call-site descriptor rather than being deferred.
    $facts = traceQbSnippet(
        "ListQueryBuilder::for(User::class)->allowedFilters([\$this->termFilter(), ListFilters::enum('status')])",
        foldedReturns: ['termFilter' => qbFoldOf("AllowedFilter::callback('q', function (\$q, \$v) { \$q->where('title', \$v); })")],
    )->facts;

    expect(entryPairs($facts->filters))->toBe([['q', 'callback'], ['status', 'enum']])
        // The written position is kept: the folded entry lands where it was written, not appended after
        // the entries the walk recovered outright.
        ->and($facts->filters[0]->typeColumn)->toBe('title')
        ->and($facts->unresolved)->toBe([]);
});

it('expands a folded array return into one entry per item, each with its own comment', function (): void {
    // `->allowedFilters(...$this->allowedFilters())`: one folded return carrying several entries, plus the
    // leading comments written next to them inside the helper.
    $helper = "[\n"
        ."    // The lifecycle status of the record.\n"
        ."    AllowedFilter::exact('status'),\n"
        ."    AllowedFilter::partial('email')->nullable(),\n"
        .']';

    $facts = traceQbSnippet(
        'ListQueryBuilder::for(User::class)->allowedFilters(...$this->allowedFilters())',
        foldedReturns: ['allowedFilters' => qbFoldOf($helper)],
    )->facts;

    expect(entryPairs($facts->filters))->toBe([['status', 'exact'], ['email', 'partial']])
        ->and($facts->filters[0]->comment)->toBe('The lifecycle status of the record.')
        // A modifier applied inside the helper survives too — it arrives on the descriptor's chain.
        ->and($facts->filters[1]->nullable)->toBeTrue()
        ->and($facts->unresolved)->toBe([]);
});

it('takes a synchronous engine\'s fold answer, leaving no placeholder token in the allow-list', function (): void {
    // FoldsCallReturns only promises the answer arrives before the trace RETURNS — an engine may answer
    // inside deferReturnFold() itself. The slot has to be registered by then, or the answer is dropped and
    // the reserved placeholder token is documented as a filter name.
    $inner = new StubTraceScope(
        new ClassT('Spatie\\QueryBuilder\\QueryBuilder'),
        ['termFilter' => qbFoldOf("AllowedFilter::exact('status')")],
    );

    $eager = new class($inner) implements FoldsCallReturns, TypeScope
    {
        public function __construct(private readonly StubTraceScope $inner) {}

        public function deferReturnFold(Node\Expr $call, callable $onFolded): bool
        {
            $queued = $this->inner->deferReturnFold($call, $onFolded);
            $this->inner->drainReturnFolds();

            return $queued;
        }

        public function typeOf(Node\Expr $expr): DType
        {
            return $this->inner->typeOf($expr);
        }

        public function constantValueOf(Node\Expr $expr): ?ConstValue
        {
            return $this->inner->constantValueOf($expr);
        }

        public function location(Node $node): SourceLocation
        {
            return $this->inner->location($node);
        }
    };

    $facts = traceQbSnippet(
        "ListQueryBuilder::for(User::class)->allowedFilters(['name', \$this->termFilter(), 'email'])",
        scope: $eager,
    )->facts;

    // The folded entry lands in the position it was written, and nothing internal survives into the names.
    expect(entryPairs($facts->filters))->toBe([['name', 'default'], ['status', 'exact'], ['email', 'default']])
        ->and($facts->unresolved)->toBe([]);
});

it('recovers a sort and a default sort built by a method', function (): void {
    $facts = traceQbSnippet(
        'ListQueryBuilder::for(User::class)->allowedSorts($this->createdAtSort())->defaultSort($this->createdAtSort())',
        foldedReturns: ['createdAtSort' => qbFoldOf("AllowedSort::field('created_at')")],
    )->facts;

    expect(entryPairs($facts->sorts))->toBe([['created_at', 'field']])
        ->and($facts->defaultSorts)->toBe(['created_at']);
});

it('degrades a deferred entry the engine could not fold, leaving no placeholder behind', function (string $chain, array $foldedReturns): void {
    $facts = traceQbSnippet($chain, foldedReturns: $foldedReturns)->facts;

    expect($facts->filters)->toBe([])
        ->and($facts->unresolved)->toHaveCount(1)
        ->and($facts->unresolved[0])->toContain('allowedFilters entry at test.php:');
})->with([
    // Queued, then answered with nothing — a branching or dynamic body.
    'fold failed' => ['ListQueryBuilder::for(User::class)->allowedFilters($this->termFilter())', ['termFilter' => [null, null]]],
    // Never queued — vendor, magic or over-budget, which is what the engine says by declining.
    'fold declined' => ['ListQueryBuilder::for(User::class)->allowedFilters(...$this->allowedFilters())', []],
    // Folded, but to a descriptor no name can be read out of.
    'fold unusable' => ['ListQueryBuilder::for(User::class)->allowedFilters($this->termFilter())', ['termFilter' => qbFoldOf('AllowedFilter::exact($dynamic)')]],
]);

it('diagnoses an unresolvable call site once, however many entries it answered with', function (): void {
    // One helper, one written call site: an array of ten unusable entries is one thing the trace could not
    // resolve, and repeating the identical note per item would just be noise in the report.
    $helper = '[AllowedFilter::exact($a), AllowedFilter::exact($b), AllowedFilter::exact($c)]';

    $facts = traceQbSnippet(
        'ListQueryBuilder::for(User::class)->allowedFilters(...$this->allowedFilters())',
        foldedReturns: ['allowedFilters' => qbFoldOf($helper)],
    )->facts;

    expect($facts->filters)->toBe([])
        ->and($facts->unresolved)->toHaveCount(1)
        ->and($facts->unresolved[0])->toContain('allowedFilters entry at test.php:');
});

it('relativises an unresolvable entry\'s file through the provenance path resolver', function (): void {
    // The note is cached inside the fragment, so an absolute path would make the cache host-specific.
    $resolver = new class implements SourcePathResolver
    {
        public function relative(string $file): string
        {
            return str_replace('/Users/dev/shop/', '', $file);
        }
    };

    $facts = traceQbSnippet(
        'QueryBuilder::for(User::class)->allowedFilters([AllowedFilter::exact($dynamic)])',
        visitor: new QueryBuilderTraceVisitor(paths: $resolver),
        scope: new StubTraceScope(new ClassT('Spatie\\QueryBuilder\\QueryBuilder'), file: '/Users/dev/shop/app/Queries/UserQuery.php'),
    )->facts;

    expect($facts->unresolved)->toHaveCount(1)
        ->and($facts->unresolved[0])->toStartWith('allowedFilters entry at app/Queries/UserQuery.php:');
});

it('degrades an unresolvable entry\'s file to its basename when there is no path resolver', function (): void {
    $facts = traceQbSnippet(
        'QueryBuilder::for(User::class)->allowedFilters([AllowedFilter::exact($dynamic)])',
        scope: new StubTraceScope(new ClassT('Spatie\\QueryBuilder\\QueryBuilder'), file: '/Users/dev/shop/app/Queries/UserQuery.php'),
    )->facts;

    expect($facts->unresolved)->toHaveCount(1)
        ->and($facts->unresolved[0])->toStartWith('allowedFilters entry at UserQuery.php:');
});
