<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Integrations\QueryBuilder\QbEntry;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderFacts;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParameters;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;

/**
 * The Query Builder integration end-to-end on the real engine. The fixture builds its allow-lists
 * inside a `UserIndexQuery` helper two calls deep and paginates behind a custom `paginateList`
 * terminal, with no doc annotations at all: the engine has to recover those facts through the trace
 * boundary, and the parameter builder has to turn them into the right query parameters under both
 * representation styles. Recovery is real; only the fold-to-facts glue is test code.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * @return array<string, mixed>
 */
function realQbHarvest(): array
{
    return FixtureRunner::traceQb(
        'app/Http/Controllers/UserListController.php',
        'App\\Http\\Controllers\\UserListController',
        'listUsers',
    );
}

function qbEntryFromRendered(string $rendered): QbEntry
{
    if (str_starts_with($rendered, "'")) {
        return new QbEntry(trim($rendered, "'"), 'default');
    }

    preg_match("/::(\\w+)\\('([^']*)'/", $rendered, $matches);

    return new QbEntry($matches[2] ?? $rendered, $matches[1] ?? 'default');
}

function factsFromRealHarvest(): QueryBuilderFacts
{
    $harvest = realQbHarvest();
    $facts = new QueryBuilderFacts;

    $facts->filters = array_map(qbEntryFromRendered(...), $harvest['filters']);
    $facts->sorts = array_map(qbEntryFromRendered(...), $harvest['sorts']);
    $facts->defaultSorts = array_map(static fn (string $d): string => trim($d, "'"), $harvest['default']);
    $facts->paginates = (bool) $harvest['paginates'];
    $facts->perPage = $harvest['perPage'];
    $facts->paginationKind = 'length';

    return $facts;
}

it('recovers the allow-lists + pagination through a two-deep helper on the real engine', function (): void {
    $harvest = realQbHarvest();

    expect($harvest['filters'])->toBe([
        "'name'",
        "AllowedFilter::exact('status')",
        "AllowedFilter::partial('email')",
    ]);
    expect($harvest['sorts'])->toBe(["'name'", "'created_at'"])
        ->and($harvest['default'])->toBe(["'name'"])
        ->and($harvest['paginates'])->toBeTrue()
        ->and($harvest['perPage'])->toBe(25);
})->group('fixture');

it('turns the real-engine harvest into bracketed query parameters', function (): void {
    $specs = (new QueryBuilderParameters)->build(factsFromRealHarvest(), new RepresentationPolicy);

    $byName = [];
    foreach ($specs as $spec) {
        $byName[$spec->name] = $spec;
    }

    expect(array_keys($byName))->toEqualCanonicalizing([
        'filter[name]', 'filter[status]', 'filter[email]', 'sort', 'page', 'per_page',
    ]);
    expect($byName['filter[status]']->description)->toBe('Exact-match filter')
        ->and($byName['sort']->schema['default'])->toBe('name')
        ->and($byName['per_page']->schema['default'])->toBe(25);
})->group('fixture');

it('turns the real-engine harvest into a deepObject filter param under the deepObject policy', function (): void {
    $specs = (new QueryBuilderParameters)->build(
        factsFromRealHarvest(),
        new RepresentationPolicy(filterStyle: 'deepObject', listStyle: 'array'),
    );

    $filter = array_values(array_filter($specs, static fn (QueryParameterSpec $s): bool => $s->name === 'filter'));

    expect($filter)->toHaveCount(1);
    expect($filter[0]->style)->toBe('deepObject')
        ->and($filter[0]->explode)->toBeTrue()
        ->and(array_keys($filter[0]->schema['properties']))->toBe(['name', 'status', 'email']);
})->group('fixture');

it('recovers a subject model and types an enum-cast exact filter through the real engine', function (): void {
    // QueryBuilderTraceVisitor recovers `QueryBuilder::for(Listing::class)` and FilterColumnResolver
    // reflects the model's `status` enum cast into the enum's backing values + #[CaseDescription]s —
    // exactly what the extension emits into the filter[status] schema.
    $harvest = FixtureRunner::traceQbEnrich(
        'app/Http/Controllers/ListingQueryController.php',
        'App\\Http\\Controllers\\ListingQueryController',
        'index',
    );

    expect($harvest['subjectModel'])->toBe('App\\Models\\Listing');

    $byName = [];
    foreach ($harvest['filters'] as $filter) {
        $byName[$filter['name']] = $filter;
    }

    // The enum-cast exact filter resolves to the enum's backing values + case descriptions.
    expect($byName['status']['columnKind'])->toBe('enum')
        ->and($byName['status']['enum'])->toBe('App\\Enums\\ListingStatus')
        ->and($byName['status']['values'])->toBe(['open', 'closed', 'draft'])
        ->and($byName['status']['descriptions'])->toBe([
            'open' => 'Visible to the public and accepting applications.',
            'closed' => 'No longer accepting applications.',
        ])
        // Cache soundness through the real engine: the enum's declaring file joins the dependency set.
        ->and($byName['status']['dependencyBasenames'])->toContain('ListingStatus.php');

    // The non-exact plain filter is not cast-typed (stays a string).
    expect($byName['title']['columnKind'])->toBeNull();

    // The leading `// Full-text match on the listing title.` comment recovers on the real engine, so
    // PHPStan's parser attributes it to the array item the way ParserFactory does and the
    // comment→description override actually fires.
    expect($byName['title']['comment'])->toBe('Full-text match on the listing title.');
})->group('fixture');

it('types a scope filter off its enum value parameter and a callback filter off its where column, on the real engine', function (): void {
    // The trace recovers Listing as the subject; ScopeParameterResolver reflects
    // scopeStatus(Builder, ListingStatus) into the enum's values + case descriptions, and the callback
    // closure's `where('active', $value)` column types off the model's boolean cast.
    $harvest = FixtureRunner::traceQbEnrich(
        'app/Http/Controllers/ListingFilterKindsController.php',
        'App\\Http\\Controllers\\ListingFilterKindsController',
        'index',
    );

    expect($harvest['subjectModel'])->toBe('App\\Models\\Listing');

    $byName = [];
    foreach ($harvest['filters'] as $filter) {
        $byName[$filter['name']] = $filter;
    }

    // Scope value parameter (backed enum) → the enum's backing values + case descriptions.
    expect($byName['status']['kind'])->toBe('scope')
        ->and($byName['status']['columnKind'])->toBe('enum')
        ->and($byName['status']['enum'])->toBe('App\\Enums\\ListingStatus')
        ->and($byName['status']['values'])->toBe(['open', 'closed', 'draft'])
        ->and($byName['status']['dependencyBasenames'])->toContain('ListingStatus.php');

    // Callback closure `where('active', $value)` → the model's boolean cast.
    expect($byName['active']['kind'])->toBe('callback')
        ->and($byName['active']['columnKind'])->toBe('scalar')
        ->and($byName['active']['scalarSchema'])->toBe(['type' => 'boolean']);
})->group('fixture');

it('follows a `$query->query()` hop into a Query class OUTSIDE the project paths (modular layout)', function (): void {
    // InvoiceController + InvoiceIndexQuery live under `modules/` (namespace Modules\Billing), outside
    // the engine's project paths (only `app/` is in them) — the modular layout that hides a real app's
    // filters. The controller body is `$this->query->query()->paginateList(...)` and the allow-lists live
    // inside InvoiceIndexQuery::query(): ListQueryBuilder, so recovering them means the engine follows
    // the QueryBuilder-return-type hop beyond the project paths, never into vendor.
    $harvest = FixtureRunner::traceQbEnrich(
        'modules/Billing/InvoiceController.php',
        'Modules\\Billing\\InvoiceController',
        'index',
    );

    // Subject model + filters recovered through the out-of-project hop.
    expect($harvest['subjectModel'])->toBe('App\\Models\\Listing')
        ->and($harvest['sorts'])->toBe(['title']);

    $byName = [];
    foreach ($harvest['filters'] as $filter) {
        $byName[$filter['name']] = $filter;
    }

    // Enum typing + the leading comment recover through the hop, same as an in-project query class.
    expect($byName)->toHaveKeys(['status', 'title'])
        ->and($byName['status']['kind'])->toBe('exact')
        ->and($byName['status']['columnKind'])->toBe('enum')
        ->and($byName['status']['values'])->toBe(['open', 'closed', 'draft'])
        ->and($byName['status']['comment'])->toBe('The listing\'s publication status.');

    // Cache soundness: the out-of-project Query class file lands in the trace's dependency set, so
    // editing it invalidates the warm fragment.
    expect($harvest['visitedBasenames'])->toContain('InvoiceIndexQuery.php');
})->group('fixture');

it('recovers allow-list entries each built by an instance method, folding what the method returns', function (): void {
    // Modules\Billing\PositionSearchQuery builds every entry through a method — nothing at the call site
    // names a single filter, so the engine has to fold each method's RETURN. `termFilter()` takes no
    // arguments at all (its name and column live only in its body); `facetFilter('status', 'status')` names
    // one only once the call-site arguments are bound to its parameters. Both through the out-of-project
    // `$query->query()` hop.
    $harvest = FixtureRunner::traceQbEnrich(
        'modules/Billing/PositionController.php',
        'Modules\\Billing\\PositionController',
        'index',
    );

    $byName = [];
    foreach ($harvest['filters'] as $filter) {
        $byName[$filter['name']] = $filter;
    }

    expect(array_keys($byName))->toBe(['q', 'status'])
        // Nothing degraded: the six diagnostics this shape used to produce are gone.
        ->and($harvest['unresolved'])->toBe([]);

    // The zero-argument method's callback filter: the name comes out of the body, and so does the column,
    // read off the closure the fold handed back as AST.
    expect($byName['q']['kind'])->toBe('callback')
        ->and($byName['q']['typeColumn'])->toBe('title');

    // The parameterised one binds both arguments, so the internal column still types off the model cast.
    expect($byName['status']['kind'])->toBe('exact')
        ->and($byName['status']['columnKind'])->toBe('enum')
        ->and($byName['status']['values'])->toBe(['open', 'closed', 'draft']);

    // Sorts and the default sort come back from the same fold.
    expect($harvest['sorts'])->toBe(['title'])
        ->and($harvest['sortKinds'])->toBe(['field'])
        ->and($harvest['defaultSorts'])->toBe(['title']);

    // Cache soundness: the folded methods' file is in the trace's dependency set, so editing a filter
    // helper invalidates the warm fragment.
    expect($harvest['visitedBasenames'])->toContain('PositionSearchQuery.php');
})->group('fixture');

it('expands an allow-list spread out of an array-returning method, and degrades a branching one', function (): void {
    // Modules\Billing\PositionFacetQuery spreads `...$this->allowedFilters()` and
    // `...$this->allowedIncludes()`, so one folded return expands into every entry it carries — with each
    // item's own leading comment, written inside the helper. Its sort method BRANCHES, which is the fold's
    // honest limit: one diagnostic, no guessed sort.
    $harvest = FixtureRunner::traceQbEnrich(
        'modules/Billing/PositionController.php',
        'Modules\\Billing\\PositionController',
        'facets',
    );

    $byName = [];
    foreach ($harvest['filters'] as $filter) {
        $byName[$filter['name']] = $filter;
    }

    expect(array_keys($byName))->toBe(['active', 'title'])
        ->and($byName['active']['kind'])->toBe('exact')
        // The comment sitting above the entry inside the helper's array survives the fold.
        ->and($byName['active']['comment'])->toBe('Whether the position is still open.')
        ->and($byName['active']['columnKind'])->toBe('scalar')
        ->and($byName['active']['scalarSchema'])->toBe(['type' => 'boolean'])
        ->and($byName['title']['kind'])->toBe('partial');

    expect($harvest['includes'])->toBe(['employer']);

    // The branching sort builder: unrecovered and diagnosed, never half-folded to one of its arms.
    expect($harvest['sorts'])->toBe([])
        ->and($harvest['unresolved'])->toHaveCount(1)
        ->and($harvest['unresolved'][0])->toContain('allowedSorts entry at ')
        ->and($harvest['unresolved'][0])->toContain('PositionFacetQuery.php');
})->group('fixture');

it('recovers the allow-lists an injected builder subclass configures in its own constructor', function (): void {
    // Modules\Billing\ChargeListQuery IS the builder: the container hands it to the action, which writes
    // nothing but `$query->paginateList(25)`. No call in that body leads to the configuration, so the
    // constructor has to be traced as a root of its own — and its `parent::__construct(Listing::query()…)`
    // is what names the subject model every filter then types off.
    $harvest = FixtureRunner::traceQbEnrich(
        'modules/Billing/ChargeController.php',
        'Modules\\Billing\\ChargeController',
        'index',
    );

    expect($harvest['subjectModel'])->toBe('App\\Models\\Listing');

    $byName = [];
    foreach ($harvest['filters'] as $filter) {
        $byName[$filter['name']] = $filter;
    }

    // Every entry, in written order, harvested exactly once (each root gets its own walk).
    expect(array_keys($byName))->toBe(['status', 'active', 'tag', 'title_search', 'state']);

    // The project factory's backed-enum class-string argument → the enum's values.
    expect($byName['status']['factoryEnum'])->toBe('App\\Enums\\ListingStatus')
        ->and($byName['status']['columnKind'])->toBe('enum')
        ->and($byName['status']['values'])->toBe(['open', 'closed', 'draft']);

    // The non-enum factory types off its key column, which needs the recovered subject model.
    expect($byName['active']['columnKind'])->toBe('scalar')
        ->and($byName['active']['scalarSchema'])->toBe(['type' => 'boolean']);

    // A first-class-callable callback: nothing at the call site says which column it compares, so the
    // filter stays an honest plain string rather than a guess.
    expect($byName['tag']['kind'])->toBe('callback')
        ->and($byName['tag']['typeColumn'])->toBeNull()
        ->and($byName['tag']['columnKind'])->toBeNull();

    // `AllowedFilter::custom('title_search', new ListingTitleSearchFilter)` — the parenless instance form,
    // whose class only the typed `new` expression at the call site can name.
    expect($byName['title_search']['kind'])->toBe('custom')
        ->and($byName['title_search']['filterClass'])->toBe('App\\Filters\\ListingTitleSearchFilter');

    // An entry only its own method body names, folded out of that body — internal column and all, so it
    // still types off the model's enum cast.
    expect($byName['state']['kind'])->toBe('exact')
        ->and($byName['state']['typeColumn'])->toBe('status')
        ->and($byName['state']['columnKind'])->toBe('enum')
        ->and($byName['state']['values'])->toBe(['open', 'closed', 'draft']);

    // The variadic-string allow-lists and the default sort come out of the same constructor.
    expect($harvest['sorts'])->toBe(['title', 'created_at'])
        ->and($harvest['includes'])->toBe(['employer'])
        ->and($harvest['defaultSorts'])->toBe(['title']);

    // The branching filter builder is the fold's honest limit: one diagnostic, no guessed arm.
    expect($harvest['unresolved'])->toHaveCount(1)
        ->and($harvest['unresolved'][0])->toContain('allowedFilters entry at ')
        ->and($harvest['unresolved'][0])->toContain('ChargeListQuery.php');

    // Cache soundness: the seeded root's file is in the dependency set the extension records, so editing
    // the query class invalidates the warm fragment.
    expect($harvest['visitedBasenames'])->toContain('ChargeListQuery.php');
})->group('fixture');

it('types a project-factory (ListFilters-style) filter through the query hop on the real engine', function (): void {
    // Modules\Billing\OrderIndexQuery builds its allow-list from a user-land factory (InvoiceFilters)
    // rather than direct AllowedFilter::* calls. The engine folds the factory call at the site —
    // recovering the backed-enum class-string argument (enum typing) and the key column (boolean cast) —
    // without descending into the factory body, all through the $query->query() hop.
    $harvest = FixtureRunner::traceQbEnrich(
        'modules/Billing/OrderController.php',
        'Modules\\Billing\\OrderController',
        'index',
    );

    expect($harvest['subjectModel'])->toBe('App\\Models\\Listing');

    $byName = [];
    foreach ($harvest['filters'] as $filter) {
        $byName[$filter['name']] = $filter;
    }

    // The enum factory recovered its backed-enum class-string argument → enum values + descriptions.
    expect($byName['status']['factoryEnum'])->toBe('App\\Enums\\ListingStatus')
        ->and($byName['status']['columnKind'])->toBe('enum')
        ->and($byName['status']['values'])->toBe(['open', 'closed', 'draft'])
        ->and($byName['status']['descriptions'])->toBe([
            'open' => 'Visible to the public and accepting applications.',
            'closed' => 'No longer accepting applications.',
        ])
        // The enum's declaring file joins the dependency set (cache soundness).
        ->and($byName['status']['dependencyBasenames'])->toContain('ListingStatus.php');

    // The boolean factory has no enum argument → types off its key column's model cast.
    expect($byName['active']['factoryEnum'])->toBeNull()
        ->and($byName['active']['columnKind'])->toBe('scalar')
        ->and($byName['active']['scalarSchema'])->toBe(['type' => 'boolean']);

    // `InvoiceFilters::state()` writes no arguments at all: its name and its enum both live in the factory
    // body, reached by binding the call to the parameter DEFAULTS and folding what it returns.
    expect(array_keys($byName))->toBe(['status', 'active', 'state'])
        ->and($byName['state']['kind'])->toBe('enum')
        ->and($byName['state']['factoryEnum'])->toBe('App\\Enums\\ListingStatus')
        ->and($byName['state']['columnKind'])->toBe('enum')
        ->and($byName['state']['values'])->toBe(['open', 'closed', 'draft']);

    // Nothing degraded on the way: the zero-argument alias is recovered, not diagnosed.
    expect($harvest['unresolved'])->toBe([]);
})->group('fixture');
