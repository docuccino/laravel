<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Integrations\QueryBuilder\QbEntry;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderFacts;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParameters;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;

/**
 * The crown jewel (design §Phase 4 — the Scramble-Pro-beater), end-to-end on the REAL engine:
 * the spike-B fixture builds its allow-lists inside a `UserIndexQuery` helper two calls deep and
 * paginates behind a custom `paginateList` terminal, with ZERO doc annotations. This asserts the
 * real PHPStan/Larastan engine recovers those facts through the trace boundary AND that the QB
 * integration's parameter builder turns them into the right query parameters under both
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
    // The REAL QueryBuilderTraceVisitor recovers `QueryBuilder::for(Listing::class)` and the REAL
    // FilterColumnResolver reflects the model's `status` enum cast → the enum's backing values +
    // #[CaseDescription]s, exactly what the extension emits into the filter[status] schema.
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

    // The leading `// Full-text match on the listing title.` comment is recovered on the real engine —
    // proving PHPStan's parser attributes the comment to the array item the way ParserFactory does, so
    // the comment→description override actually fires (previously proven only over bare parser nodes).
    expect($byName['title']['comment'])->toBe('Full-text match on the listing title.');
})->group('fixture');

it('types a scope filter off its enum value parameter and a callback filter off its where column, on the real engine', function (): void {
    // The REAL trace recovers Listing as the subject; ScopeParameterResolver reflects
    // scopeStatus(Builder, ListingStatus) → the enum's values + case descriptions, and the callback
    // closure's `where('active', $value)` column types off the model's boolean cast — round-2 kinds.
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
    // InvoiceController + InvoiceIndexQuery live under `modules/` (namespace Modules\Billing), which is
    // NOT in the engine's project paths (only `app/` is) — exactly the modular layout that hides a real
    // app's filters. The controller body is `$this->query->query()->paginateList(...)`; the allow-lists live
    // inside InvoiceIndexQuery::query(): ListQueryBuilder. Recovering them proves the engine follows
    // the QueryBuilder-return-type hop beyond the project paths (never into vendor).
    $harvest = FixtureRunner::traceQbEnrich(
        'modules/Billing/InvoiceController.php',
        'Modules\\Billing\\InvoiceController',
        'index',
    );

    // Subject model + filters recovered THROUGH the out-of-project hop.
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
    // editing it invalidates the warm fragment (the follow-beyond descent records it like any file).
    expect($harvest['visitedBasenames'])->toContain('InvoiceIndexQuery.php');
})->group('fixture');

it('types a project-factory (ListFilters-style) filter through the query hop on the real engine', function (): void {
    // Modules\Billing\OrderIndexQuery builds its allow-list from a user-land factory (InvoiceFilters)
    // rather than direct AllowedFilter::* calls. The real engine must fold the factory call at the site
    // — recovering the backed-enum class-string argument (→ enum typing) and the key column (→ boolean
    // cast) — without descending into the factory body, all through the $query->query() hop.
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
})->group('fixture');
