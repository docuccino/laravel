<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderConfig;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParameters;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Spatie\QueryBuilder\AllowedFilter;
use Workbench\App\Models\Beacon;

/**
 * End-to-end proof of the QB filter-kind ENRICHMENT (round 2) in-process: a scripted trace over a real
 * workbench model (`Gadget`) drives the real extension, so each kind's value types off the model's
 * cast / scope signature / custom filter, and the partial-on-enum nudge fires — without the real
 * engine (that is proven behaviourally in the fixture group).
 */
function runFilterKinds(string $chain, ?QueryBuilderConfig $config = null, array $integrations = []): array
{
    $engine = new StubTypeEngine(traces: [
        'App\\Gadgets::index' => TraceScript::forChain($chain),
    ]);

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: new ActionRef('', 'App\\Gadgets', 'index'),
        attributes: new AttributeSet,
        engine: $engine,
        document: new DocumentConfig('default', [], raw: $integrations === [] ? [] : ['integrations' => $integrations]),
    );

    $operation = new OperationDraft;
    (new QueryBuilderParametersExtension($config ?? new QueryBuilderConfig))->handle($operation, $context);

    $byName = [];
    foreach ($operation->freeze()->parameters as $parameter) {
        $byName[$parameter->name] = $parameter->toArray();
    }

    return [$byName, $context->components->diagnostics(), $context->dependencyFiles()];
}

$chain = 'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
    ."AllowedFilter::exact('id'), "                                                          // exact on the primary key → the key schema
    ."'name', "                                                                              // bare, uncast → plain string, no nudge
    ."'status', "                                                                            // bare, enum-cast → partial-on-enum nudge
    ."AllowedFilter::scope('minScore'), "                                                    // scope int value
    ."AllowedFilter::callback('active', function (\$q, \$value) { \$q->where('active', \$value); }), " // callback bool column
    ."AllowedFilter::operator('score', FilterOperator::EQUAL), "                             // static operator int
    ."AllowedFilter::custom('flag', \\Workbench\\App\\Filters\\DocumentedFilter::class), "   // custom attribute (int, example 42)
    ."AllowedFilter::custom('sc', \\Workbench\\App\\Filters\\ScoreFilter::class), "          // custom __invoke body (score int)
    ."AllowedFilter::custom('from', \\Workbench\\App\\Filters\\DateFilter::class, 'starts_at'), " // generic custom, declared internal name → datetime cast
    ."AllowedFilter::custom('starts_at', \\Workbench\\App\\Filters\\DateFilter::class), "    // generic custom, no internal name → its own name is the column
    ."AllowedFilter::custom('opaque', \\Workbench\\App\\Filters\\CompositeFilter::class), "  // custom nothing can type → explicit unconstrained schema
    .'AllowedFilter::trashed(),'                                                             // trashed with/only enum
    .'])->paginate()';

it('types each recovered filter kind off the model and applies the custom-filter attribute', function () use ($chain): void {
    [$byName] = runFilterKinds($chain);

    // Exact filter on the primary key → the model's key schema (Gadget keys on a default int id).
    expect($byName['filter[id]']['schema']['type'])->toBe('integer');

    // Bare uncast filter → plain string, generic description.
    expect($byName['filter[name]']['schema']['type'])->toBe('string')
        ->and($byName['filter[name]']['schema'])->not->toHaveKey('enum')
        ->and($byName['filter[name]']['description'])->toBe('Substring match on `name`.');

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

    // A GENERIC custom filter (no attribute, no literal body column) types off the column binding the
    // AllowedFilter call declares — the internal name, else the filter's own name. `format: date-time`
    // can only come from the `starts_at` datetime cast, so this provably resolved rather than fell back.
    expect($byName['filter[from]']['schema']['type'])->toBe('string')
        ->and($byName['filter[from]']['schema']['format'])->toBe('date-time')
        ->and($byName['filter[starts_at]']['schema']['format'])->toBe('date-time');

    // A custom filter nothing can type (multi-clause __invoke, no attribute, no column binding) still
    // publishes an explicit unconstrained schema — a parameter without one is invalid OAS, not vague.
    expect($byName['filter[opaque]'])->toHaveKey('schema')
        ->and($byName['filter[opaque]']['schema'])->toBe([]);

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

it('types a belongsTo foreign-key filter off the related model\'s uuid key and keys the fragment on it', function (): void {
    [$byName, , $files] = runFilterKinds(
        "QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters([AllowedFilter::exact('beacon_id')])->paginate()",
    );

    // `format: uuid` can only come from Beacon's HasUuids key — a fallback would be a bare string —
    // and the related model's file must key the fragment so editing Beacon re-documents the route.
    expect($byName['filter[beacon_id]']['schema']['type'])->toBe('string')
        ->and($byName['filter[beacon_id]']['schema']['format'])->toBe('uuid')
        ->and($files)->toContain((new ReflectionClass(Beacon::class))->getFileName());
});

it('does not nudge when a partial filter targets a non-enum column', function (): void {
    [, $diagnostics] = runFilterKinds(
        "QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['name'])->paginate()",
    );

    $codes = array_map(static fn ($d): string => $d->code, $diagnostics);
    expect($codes)->not->toContain('query-builder.partial-on-enum');
});

it('emits sort and include as comma-serialised enum arrays end to end', function (): void {
    [$byName] = runFilterKinds(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)'
        ."->allowedSorts(['name', 'score'])->defaultSort('-name')"
        ."->allowedIncludes(['maker.region', 'partsCount'])->paginate()",
    );

    expect($byName['sort']['style'])->toBe('form')
        ->and($byName['sort']['explode'])->toBeFalse()
        ->and($byName['sort']['schema']['type'])->toBe('array')
        ->and($byName['sort']['schema']['items']['enum'])->toBe(['name', '-name', 'score', '-score'])
        ->and($byName['sort']['schema']['default'])->toBe(['-name']);

    // The bare nested string legalizes its partials and their Count/Exists forms exactly as Spatie
    // does; the already-suffixed one is that include alone.
    expect($byName['include']['style'])->toBe('form')
        ->and($byName['include']['explode'])->toBeFalse()
        ->and($byName['include']['schema']['items']['enum'])
        ->toBe(['maker', 'makerCount', 'makerExists', 'maker.region', 'partsCount']);
});

it('degrades sort, include and fields to plain strings and says so, on a pre-v7 package', function (): void {
    $chain = 'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)'
        ."->allowedSorts(['name'])->allowedIncludes(['maker'])->allowedFields(['label'])->paginate()";

    [$byName, $diagnostics] = runFilterKinds($chain, new QueryBuilderConfig(spatieMajor: 6));

    $legacy = array_values(array_filter($diagnostics, fn ($d): bool => $d->code === 'query-builder.legacy-package-version'));

    expect($byName['sort']['schema']['type'])->toBe('string')
        ->and($byName['sort']['schema'])->not->toHaveKey('items')
        ->and($byName['sort'])->not->toHaveKey('style')
        ->and($byName['include']['schema']['type'])->toBe('string')
        ->and($byName['include']['schema'])->not->toHaveKey('items')
        ->and($byName['include']['description'])->toBe('Include related resources: maker.')
        ->and($byName['fields']['schema']['type'])->toBe('string')
        ->and($byName['fields']['schema'])->not->toHaveKey('items')
        ->and($legacy)->toHaveCount(1)
        ->and($legacy[0]->message)->toBe('spatie/laravel-query-builder below v7 is installed, so the sort/include/fields allow-lists are documented as plain strings rather than value enums.');
});

it('never reports a legacy package on the supported major, and skips the report where no list was recovered', function (): void {
    $sorted = 'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)'
        ."->allowedSorts(['name'])->paginate()";
    [, $diagnostics] = runFilterKinds($sorted);

    // Filters-only on a legacy install loses nothing, so there is nothing to say.
    $filtersOnly = 'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)'
        ."->allowedFilters(['name'])->paginate()";
    [, $legacyDiagnostics] = runFilterKinds($filtersOnly, new QueryBuilderConfig(spatieMajor: 6));

    $codes = array_map(fn ($d): string => $d->code, [...$diagnostics, ...$legacyDiagnostics]);
    expect($codes)->not->toContain('query-builder.legacy-package-version');
});

it('reports a legacy package where fields alone were recovered', function (): void {
    $fieldsOnly = 'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)'
        ."->allowedFields(['label'])->paginate()";

    [$byName, $diagnostics] = runFilterKinds($fieldsOnly, new QueryBuilderConfig(spatieMajor: 6));

    expect($byName['fields']['schema']['type'])->toBe('string')
        ->and(array_map(fn ($d): string => $d->code, $diagnostics))->toContain('query-builder.legacy-package-version');
});

/**
 * The recovery half of the filter-description override: the sentences come off the DOCUMENT's own
 * `integrations.query_builder` bag, exactly where `pagination_terminals` comes from, and reach the
 * emitted parameter through the real extension. Kinds the document does not name keep their defaults,
 * and the request-form notes still compose after the configured lead.
 */
it('describes filters with the sentences the document configured, keeping the defaults it did not name', function (): void {
    $chain = 'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
        ."AllowedFilter::exact('status'), "
        ."'name', "
        .'])->paginate()';

    [$configured] = runFilterKinds($chain, integrations: ['query_builder' => ['filter_descriptions' => [
        'exact' => 'Matches `%field%` exactly.',
    ]]]);
    [$default] = runFilterKinds($chain);

    expect($configured['filter[status]']['description'])
        ->toBe('Matches `status` exactly. Accepts a comma-separated list of values (matched as `whereIn`).')
        // Not named, so unchanged.
        ->and($configured['filter[name]']['description'])->toBe($default['filter[name]']['description'])
        // And the schema is untouched: prose is all this setting can move.
        ->and($configured['filter[status]']['schema'])->toBe($default['filter[status]']['schema']);
});

it('emits the same parameters when the document configures no filter descriptions', function (mixed $bag): void {
    $chain = 'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
        ."AllowedFilter::exact('status'), 'name', "
        .'])->paginate()';

    [$bare] = runFilterKinds($chain);
    [$withBag] = runFilterKinds($chain, integrations: ['query_builder' => ['filter_descriptions' => $bag]]);

    expect($withBag)->toBe($bare);
})->with([
    'an empty map' => [[]],
    'a key naming no kind' => [['wibble' => 'Never reached.']],
    'a non-string sentence' => [['exact' => ['nope']]],
]);

it('detects the installed package as the supported major', function (): void {
    // The real-path guard on version detection: the suite runs against the vendored v7, so the
    // parser reading anything else here means detection stopped seeing real versions.
    expect(QueryBuilderConfig::majorOf(InstalledVersions::getVersion('spatie/laravel-query-builder')))->toBe(7);
});

/**
 * The v7 spellings, end to end: a kind is the factory method the app wrote, so these reach the table
 * under their own names and get the match they perform rather than the opaque fallback.
 */
it('describes the filter kinds the installed major spells', function (): void {
    [$byName] = runFilterKinds(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters(['
        ."AllowedFilter::beginsWith('name'), "
        ."AllowedFilter::endsWith('slug'), "
        ."AllowedFilter::groupOr('search', [AllowedFilter::partial('name'), AllowedFilter::partial('slug')]), "
        ."AllowedFilter::groupAnd('both', [AllowedFilter::partial('name'), AllowedFilter::partial('slug')]), "
        .'])->paginate()'
    );

    expect($byName['filter[name]']['description'])->toBe('Prefix match on `name`.')
        ->and($byName['filter[slug]']['description'])->toBe('Suffix match on `slug`.')
        ->and($byName['filter[search]']['description'])->toBe('Matches records where at least one of the conditions grouped under `search` holds.')
        ->and($byName['filter[both]']['description'])->toBe('Matches records where every condition grouped under `both` holds.')
        // A group's members are not filters of their own — the group publishes one key, and the value
        // it takes is a string whatever its members type off.
        ->and($byName['filter[search]']['schema']['type'])->toBe('string')
        ->and($byName['filter[search]']['schema'])->not->toHaveKey('enum')
        ->and($byName)->toHaveCount(5); // the four filters + the page parameter
});

it('describes the filters the installed package actually ships', function (): void {
    // The description dataset is hand-maintained, so it only proves the rows it LISTS. This reads the
    // vendor instead: every AllowedFilter factory the installed version ships owes the table a row, or
    // a real call site falls through to the opaque sentence. One-directional on purpose — our table
    // also carries the v6 spellings and kinds a lower-resolved 7.0 has not added yet, so a
    // --prefer-lowest leg checks fewer factories and still passes while the locked leg catches the
    // next release. The floor keeps a scan that stopped matching from passing on an empty set: 7.0
    // already ships ten factories, and the group pair arrived in 7.3.
    $factories = array_values(array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            (new ReflectionClass(AllowedFilter::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => $method->isStatic()
                && $method->getDeclaringClass()->getName() === AllowedFilter::class
                && (string) $method->getReturnType() === 'static',
        ),
    ));

    expect(count($factories))->toBeGreaterThanOrEqual(10)
        ->and(array_diff($factories, QueryBuilderParameters::filterKinds()))->toBe([]);
});
