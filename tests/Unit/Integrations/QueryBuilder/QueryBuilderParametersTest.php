<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Laravel\Integrations\QueryBuilder\QbEntry;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderConfig;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderFacts;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParameters;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;

/** The enum column schema an exact filter is enriched with (backing values + case descriptions). */
function enumColumnSchema(): array
{
    return [
        'type' => 'string',
        'enum' => ['draft', 'published', 'archived'],
        'x-enumDescriptions' => ['draft' => 'Not yet.', 'published' => 'Live.'],
    ];
}

/**
 * Dataset coverage over the representation-policy expression of every recovered fact kind, in BOTH
 * the default (bracketed / comma) and alternative (deepObject / array) styles — the semantic facts
 * are identical, only the OAS expression changes (design §Representation policies).
 */
function factsWith(callable $mutate): QueryBuilderFacts
{
    $facts = new QueryBuilderFacts;
    $mutate($facts);

    return $facts;
}

function bracketedPolicy(): RepresentationPolicy
{
    return new RepresentationPolicy;
}

function deepObjectPolicy(): RepresentationPolicy
{
    return new RepresentationPolicy(filterStyle: 'deepObject', listStyle: 'array');
}

it('expresses filters as flat bracketed params by default, with kind descriptions', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [new QbEntry('status', 'exact'), new QbEntry('email', 'partial')];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect(array_keys($byName))->toBe(['filter[status]', 'filter[email]']);
    expect($byName['filter[status]']->schema)->toBe(['type' => 'string'])
        ->and($byName['filter[status]']->description)->toBe('Exact-match filter')
        ->and($byName['filter[email]']->description)->toBe('Partial-match filter');
});

it('expresses filters as a single deepObject param under the deepObject policy', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [new QbEntry('status', 'exact'), new QbEntry('email', 'partial')];
    });

    $specs = (new QueryBuilderParameters)->build($facts, deepObjectPolicy());

    expect($specs)->toHaveCount(1);
    expect($specs[0]->name)->toBe('filter')
        ->and($specs[0]->style)->toBe('deepObject')
        ->and($specs[0]->explode)->toBeTrue()
        ->and($specs[0]->schema)->toBe([
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'Exact-match filter'],
                'email' => ['type' => 'string', 'description' => 'Partial-match filter'],
            ],
        ]);
});

it('expresses sort as a comma string with a default by default', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->sorts = [new QbEntry('name', 'default'), new QbEntry('created_at', 'field')];
        $f->defaultSorts = ['name'];
    });

    $specs = (new QueryBuilderParameters)->build($facts, bracketedPolicy());

    expect($specs)->toHaveCount(1);
    expect($specs[0]->name)->toBe('sort')
        ->and($specs[0]->schema)->toBe(['type' => 'string', 'default' => 'name'])
        ->and($specs[0]->style)->toBeNull()
        ->and($specs[0]->description)->toContain('prefix `-` for descending');
});

it('expresses sort as an exploded array with an enum incl. the descending forms under the array policy', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->sorts = [new QbEntry('name', 'default'), new QbEntry('created_at', 'field')];
        $f->defaultSorts = ['name'];
    });

    $specs = (new QueryBuilderParameters)->build($facts, deepObjectPolicy());

    expect($specs[0]->name)->toBe('sort')
        ->and($specs[0]->style)->toBe('form')
        ->and($specs[0]->explode)->toBeFalse()
        ->and($specs[0]->schema)->toBe([
            'type' => 'array',
            'items' => ['type' => 'string', 'enum' => ['name', '-name', 'created_at', '-created_at']],
            'default' => ['name'],
        ]);
});

it('expresses include as a comma string by default and an exploded array under the array policy', function (RepresentationPolicy $policy, array $expected): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->includes = [new QbEntry('author', 'default'), new QbEntry('comments', 'default')];
    });

    $specs = (new QueryBuilderParameters)->build($facts, $policy);

    expect($specs[0]->name)->toBe('include')
        ->and($specs[0]->schema)->toBe($expected);
})->with([
    'comma' => [new RepresentationPolicy, ['type' => 'string']],
    'array' => [new RepresentationPolicy(listStyle: 'array'), [
        'type' => 'array',
        'items' => ['type' => 'string', 'enum' => ['author', 'comments']],
    ]],
]);

it('groups sparse fields into fields[type] params by default', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->fields = [new QbEntry('articles.title', 'field'), new QbEntry('articles.body', 'field'), new QbEntry('author.name', 'field')];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect(array_keys($byName))->toBe(['fields[articles]', 'fields[author]']);
    expect($byName['fields[articles]']->schema)->toBe(['type' => 'string'])
        ->and($byName['fields[articles]']->description)->toBe('Comma-separated fields: title, body.');
});

it('groups sparse fields into a single deepObject fields param under the deepObject policy', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->fields = [new QbEntry('articles.title', 'field'), new QbEntry('author.name', 'field')];
    });

    $specs = (new QueryBuilderParameters)->build($facts, deepObjectPolicy());

    expect($specs)->toHaveCount(1);
    expect($specs[0]->name)->toBe('fields')
        ->and($specs[0]->style)->toBe('deepObject')
        ->and($specs[0]->schema['type'])->toBe('object')
        ->and(array_keys($specs[0]->schema['properties']))->toBe(['articles', 'author']);
});

it('adds page/per_page for length + simple pagination and cursor/per_page for cursor', function (string $kind, ?int $perPage, array $expectedNames, int $expectedPerPageDefault): void {
    $facts = factsWith(function (QueryBuilderFacts $f) use ($kind, $perPage): void {
        $f->paginates = true;
        $f->paginationKind = $kind;
        $f->perPage = $perPage;
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect(array_keys($byName))->toBe($expectedNames);
    expect($byName['per_page']->schema)->toBe(['type' => 'integer', 'default' => $expectedPerPageDefault, 'minimum' => 1]);
})->with([
    'length with recovered per-page' => ['length', 25, ['page', 'per_page'], 25],
    'simple falling back to default per-page' => ['simple', null, ['page', 'per_page'], 15],
    'cursor' => ['cursor', 50, ['cursor', 'per_page'], 50],
]);

it('contributes nothing when no facts were recovered', function (): void {
    expect((new QueryBuilderParameters)->build(new QueryBuilderFacts, bracketedPolicy()))->toBe([]);
});

// --- Enum-cast / column-typed exact filters (comma/whereIn array modelling, feature 1) ---

it('models an enum-typed exact filter as a comma-serialised array in the bracketed policy', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [(new QbEntry('status', 'exact'))->withColumn(enumColumnSchema(), enumTyped: true)];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    // Comma form: array of the enum values, style form + explode false serialises to `filter[status]=a,b`.
    expect($byName['filter[status]']->schema)->toBe(['type' => 'array', 'items' => enumColumnSchema()])
        ->and($byName['filter[status]']->style)->toBe('form')
        ->and($byName['filter[status]']->explode)->toBeFalse()
        ->and($byName['filter[status]']->description)->toBe('Exact-match filter. Accepts a comma-separated list of values (matched as `whereIn`).');
});

it('models an enum-typed exact filter as an array property under the deepObject policy', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [(new QbEntry('status', 'exact'))->withColumn(enumColumnSchema(), enumTyped: true)];
    });

    $specs = (new QueryBuilderParameters)->build($facts, deepObjectPolicy());

    expect($specs[0]->schema['properties']['status'])->toBe([
        'type' => 'array',
        'items' => enumColumnSchema(),
        'description' => 'Exact-match filter. Accepts a comma-separated list of values (matched as `whereIn`).',
    ]);
});

it('emits a native (non-enum) cast schema as its scalar type keeping the plain-string serialization', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [(new QbEntry('active', 'exact'))->withColumn(['type' => 'boolean'], enumTyped: false)];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    // No array wrapping, no whereIn note, no style override — churn control for non-enum filters.
    expect($byName['filter[active]']->schema)->toBe(['type' => 'boolean'])
        ->and($byName['filter[active]']->style)->toBeNull()
        ->and($byName['filter[active]']->description)->toBe('Exact-match filter');
});

it('carries a constant ->default() onto the schema as the single value (not a wrapped list)', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [
            (new QbEntry('status', 'exact', hasDefault: true, default: 'published'))
                ->withColumn(enumColumnSchema(), enumTyped: true),
        ];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect($byName['filter[status]']->schema)->toBe(['type' => 'array', 'items' => enumColumnSchema(), 'default' => 'published']);
});

it('appends the nullable note to the description without adding a null enum case', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [
            (new QbEntry('status', 'exact', nullable: true))->withColumn(enumColumnSchema(), enumTyped: true),
        ];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect($byName['filter[status]']->description)
        ->toBe('Exact-match filter. Accepts a comma-separated list of values (matched as `whereIn`). Accepts `null` to filter for absent values.')
        ->and($byName['filter[status]']->schema['items']['enum'])->toBe(['draft', 'published', 'archived']);
});

it('uses a leading comment as the description, overriding the generic kind fragment', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [new QbEntry('status', 'exact', comment: 'The lifecycle status.')];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect($byName['filter[status]']->description)->toBe('The lifecycle status.');
});

it('follows the package config renamed parameter names', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [new QbEntry('status', 'exact')];
        $f->sorts = [new QbEntry('name', 'default')];
        $f->includes = [new QbEntry('author', 'default')];
        $f->fields = [new QbEntry('articles.title', 'field')];
    });

    $config = new QueryBuilderConfig(filter: 'f', sort: 's', include: 'inc', fields: 'flds');
    $names = array_map(static fn (QueryParameterSpec $s): string => $s->name, (new QueryBuilderParameters)->build($facts, bracketedPolicy(), $config));

    expect($names)->toBe(['f[status]', 's', 'inc', 'flds[articles]']);
});

// --- Filter-kind breadth (round 2): trashed, scope/callback single-value enum, example ---

it('expresses the trashed filter as a fixed with/only enum in both policies', function (RepresentationPolicy $policy, callable $extract): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [new QbEntry('trashed', 'trashed')];
    });

    $schema = $extract((new QueryBuilderParameters)->build($facts, $policy));

    expect($schema['type'])->toBe('string')
        ->and($schema['enum'])->toBe(['with', 'only']);
})->with([
    'bracketed' => [new RepresentationPolicy, static fn (array $specs): array => specsByName($specs)['filter[trashed]']->schema],
    'deepObject' => [deepObjectPolicy(), static fn (array $specs): array => $specs[0]->schema['properties']['trashed']],
]);

it('describes the trashed filter with its with/only/omit contract', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [new QbEntry('trashed', 'trashed')];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect($byName['filter[trashed]']->description)->toContain('with')
        ->and($byName['filter[trashed]']->description)->toContain('only')
        ->and($byName['filter[trashed]']->description)->toContain('omit');
});

it('models a scope/callback enum column as a SINGLE enum value, not a whereIn array', function (string $kind): void {
    // Non-exact kinds are single-value comparisons (`where(col, $value)`), so enumTyped is false and
    // the enum schema is used directly — no `type: array` / `whereIn` note.
    $facts = factsWith(function (QueryBuilderFacts $f) use ($kind): void {
        $f->filters = [(new QbEntry('status', $kind))->withColumn(enumColumnSchema(), enumTyped: false)];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect($byName['filter[status]']->schema)->toBe(enumColumnSchema())
        ->and($byName['filter[status]']->style)->toBeNull()
        ->and($byName['filter[status]']->description)->not->toContain('whereIn');
})->with(['scope', 'callback', 'operator', 'custom']);

it('threads a custom-filter example onto the parameter in both policies', function (RepresentationPolicy $policy, callable $extract): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [(new QbEntry('score', 'custom'))->withColumn(['type' => 'integer'], enumTyped: false, comment: 'Minimum score.', example: 42)];
    });

    [$example, $description] = $extract((new QueryBuilderParameters)->build($facts, $policy));

    expect($example)->toBe(42)
        ->and($description)->toContain('Minimum score.');
})->with([
    'bracketed' => [new RepresentationPolicy, static function (array $specs): array {
        $spec = specsByName($specs)['filter[score]'];

        return [$spec->example, (string) $spec->description];
    }],
    'deepObject' => [deepObjectPolicy(), static function (array $specs): array {
        $property = $specs[0]->schema['properties']['score'];

        return [$property['example'], (string) $property['description']];
    }],
]);

it('describes every filter kind, falling back to a generic label for an unknown kind', function (string $kind, string $expected): void {
    // Coverage standard: every FILTER_DESCRIPTIONS entry + the unknown-kind degradation ('Filter').
    $facts = factsWith(function (QueryBuilderFacts $f) use ($kind): void {
        $f->filters = [new QbEntry('thing', $kind)];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect($byName['filter[thing]']->description)->toBe($expected);
})->with([
    'default' => ['default', 'Partial-match filter'],
    'partial' => ['partial', 'Partial-match filter'],
    'exact' => ['exact', 'Exact-match filter'],
    'beginsWithStrict' => ['beginsWithStrict', 'Begins-with filter'],
    'endsWithStrict' => ['endsWithStrict', 'Ends-with filter'],
    'scope' => ['scope', 'Query-scope filter'],
    'callback' => ['callback', 'Custom filter'],
    'custom' => ['custom', 'Custom filter'],
    'operator' => ['operator', 'Operator filter'],
    'belongsTo' => ['belongsTo', 'Relationship filter'],
    'trashed' => ['trashed', 'Soft-delete filter: `with` includes soft-deleted records, `only` returns only soft-deleted; omit to exclude them.'],
    'unknown fallback' => ['wibble', 'Filter'],
]);
