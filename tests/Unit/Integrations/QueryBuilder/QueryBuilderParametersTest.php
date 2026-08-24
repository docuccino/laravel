<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Laravel\Integrations\QueryBuilder\QbEntry;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderConfig;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderFacts;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParameters;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;
use Docuccino\Laravel\Integrations\Support\RequestPageSizeKey;

/**
 * The enum column schema an exact filter is enriched with: backing values plus the case prose exactly
 * as the emitter attaches it. `archived` carries none, so the value-keyed map is withheld (its contract
 * is completeness) and the prose travels index-parallel — a partial map is a shape nothing can produce.
 */
function enumColumnSchema(): array
{
    return [
        'type' => 'string',
        'enum' => ['draft', 'published', 'archived'],
        'x-enum-descriptions' => ['Not yet.', 'Live.', ''],
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
        ->and($byName['filter[status]']->description)->toBe('Exact match on `status`.')
        ->and($byName['filter[email]']->description)->toBe('Substring match on `email`.');
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
                'status' => ['type' => 'string', 'description' => 'Exact match on `status`.'],
                'email' => ['type' => 'string', 'description' => 'Substring match on `email`.'],
            ],
        ]);
});

it('expresses sort as a comma-serialised enum array under either list style', function (RepresentationPolicy $policy): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->sorts = [new QbEntry('name', 'default'), new QbEntry('created_at', 'field')];
        $f->defaultSorts = ['name'];
    });

    $specs = (new QueryBuilderParameters)->build($facts, $policy);

    expect($specs)->toHaveCount(1);
    expect($specs[0]->name)->toBe('sort')
        ->and($specs[0]->style)->toBe('form')
        ->and($specs[0]->explode)->toBeFalse()
        ->and($specs[0]->description)->toContain('prefix `-` for descending')
        ->and($specs[0]->schema)->toBe([
            'type' => 'array',
            'items' => [
                'type' => 'string',
                'enum' => ['name', '-name', 'created_at', '-created_at'],
                'x-enum-varnames' => ['Name', 'NameDesc', 'CreatedAt', 'CreatedAtDesc'],
                'x-enumNames' => ['Name', 'NameDesc', 'CreatedAt', 'CreatedAtDesc'],
            ],
            'default' => ['name'],
        ]);
})->with([
    'comma' => [new RepresentationPolicy],
    'array' => [new RepresentationPolicy(listStyle: 'array')],
]);

it('composes several default sorts into an array default, a descending one as written', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->sorts = [new QbEntry('issued_at', 'default'), new QbEntry('total', 'default')];
        $f->defaultSorts = ['-issued_at', 'total'];
    });

    $schema = (new QueryBuilderParameters)->build($facts, bracketedPolicy())[0]->schema;

    expect($schema['default'])->toBe(['-issued_at', 'total'])
        ->and($schema['items']['enum'])->toContain('-issued_at');
});

/**
 * A `defaultSort` needs no allow-listing — Spatie validates only requested sorts — so a default can
 * sit outside the emitted enum. A default violating its own schema would be a lie: any out-of-enum
 * value moves the WHOLE default into the description, and an all-listed one keeps the schema default.
 */
it('drops a default sort outside its own enum into the description', function (array $defaults, bool $onSchema, string $note): void {
    $facts = factsWith(function (QueryBuilderFacts $f) use ($defaults): void {
        $f->sorts = [new QbEntry('name', 'default')];
        $f->defaultSorts = $defaults;
    });

    $spec = (new QueryBuilderParameters)->build($facts, bracketedPolicy())[0];

    if ($onSchema) {
        expect($spec->schema['default'])->toBe($defaults)
            ->and($spec->description)->not->toContain('Defaults to');
    } else {
        expect($spec->schema)->not->toHaveKey('default')
            ->and($spec->description)->toEndWith($note);
    }
})->with([
    'non-listed ascending' => [['created_at'], false, 'Defaults to `created_at`.'],
    'non-listed descending' => [['-created_at'], false, 'Defaults to `-created_at`.'],
    'mixed listed and non-listed drops the whole default' => [['name', '-created_at'], false, 'Defaults to `name`, `-created_at`.'],
    'all listed keeps the schema default' => [['-name'], true, ''],
]);

/**
 * Spatie splits list values on `query-builder.delimiter`, so the comma-array contract is only
 * truthful on the default: another separator degrades sort/include and the enum whereIn filters to a
 * plain string naming it (values restated where inline), and no separator at all means one value per
 * request — the item schema itself.
 */
it('degrades every comma-form list under a custom delimiter', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->sorts = [new QbEntry('name', 'default')];
        $f->includes = [new QbEntry('author', 'relationship')];
        $f->filters = [(new QbEntry('status', 'exact'))->withColumn(enumColumnSchema(), enumTyped: true)];
        $f->defaultSorts = ['-name'];
    });
    $config = new QueryBuilderConfig(delimiter: '|');

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy(), $config));

    expect($byName['sort']->schema)->toBe(['type' => 'string'])
        ->and($byName['sort']->style)->toBeNull()
        ->and($byName['sort']->description)->toContain('Values are separated by `|`.')
        ->and($byName['sort']->description)->toEndWith('Defaults to `-name`.')
        ->and($byName['include']->schema)->toBe(['type' => 'string'])
        ->and($byName['include']->description)->toContain('Values are separated by `|`.')
        ->and($byName['filter[status]']->schema)->toBe(['type' => 'string'])
        ->and($byName['filter[status]']->style)->toBeNull()
        ->and($byName['filter[status]']->description)->toContain('Accepts a `|`-separated list of values (matched as `whereIn`).')
        ->and($byName['filter[status]']->description)->toContain('Values: draft, published, archived.');

    $property = (new QueryBuilderParameters)->build($facts, deepObjectPolicy(), $config)[0]->schema['properties']['status'];
    expect($property['type'])->toBe('string')->and($property)->not->toHaveKey('items');
});

it('emits single-value item schemas when an empty delimiter disables splitting', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->sorts = [new QbEntry('name', 'default')];
        $f->filters = [(new QbEntry('status', 'exact'))->withColumn(enumColumnSchema(), enumTyped: true)];
    });
    $config = new QueryBuilderConfig(delimiter: '');

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy(), $config));

    // One value per request: the item enum IS the parameter's shape — decoration included — and no
    // list note is claimed.
    expect($byName['sort']->schema)->toBe([
        'type' => 'string',
        'enum' => ['name', '-name'],
        'x-enum-varnames' => ['Name', 'NameDesc'],
        'x-enumNames' => ['Name', 'NameDesc'],
    ])
        ->and($byName['filter[status]']->schema)->toBe(enumColumnSchema())
        ->and($byName['filter[status]']->description)->not->toContain('whereIn');
});

/**
 * Below spatie/laravel-query-builder v7 the enum grammar itself is unproven — the explicit factory
 * minted Count/Exists + partials there, and the old config keys are not read — so sort, include and
 * the fields groups all degrade to the vague-true plain string: defaults in prose, no separator note
 * (a pre-v7 install configured the delimiter another way). Filter typing is cast-driven, not
 * grammar-driven, so it is untouched. The `< 7` boundary itself is proven over majors in
 * QueryBuilderConfigTest.
 */
it('degrades sort and include to plain strings on a pre-v7 package', function (int $major): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->sorts = [new QbEntry('name', 'default')];
        $f->includes = [new QbEntry('author', 'relationship')];
        $f->filters = [(new QbEntry('status', 'exact'))->withColumn(enumColumnSchema(), enumTyped: true)];
        $f->defaultSorts = ['-created_at'];
    });
    $config = new QueryBuilderConfig(delimiter: '|', spatieMajor: $major);

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy(), $config));

    expect($byName['sort']->schema)->toBe(['type' => 'string'])
        ->and($byName['sort']->style)->toBeNull()
        ->and($byName['sort']->explode)->toBeNull()
        ->and($byName['sort']->description)->toBe('Sort by: name (prefix `-` for descending). Defaults to `-created_at`.')
        ->and($byName['include']->schema)->toBe(['type' => 'string'])
        ->and($byName['include']->description)->toBe('Include related resources: author.')
        ->and($byName['filter[status]']->schema)->toBe(['type' => 'string'])
        ->and($byName['filter[status]']->description)->toContain('Accepts a `|`-separated list of values (matched as `whereIn`).');
})->with(['v6' => [6]]);

/**
 * An allow-list entry the trace could not fold leaves its SIBLINGS recovered — each of them true, and
 * their set short. A closed enum over a short set tells a generated client to reject a value the server
 * accepts, so that ONE list widens to the honest plain string; the others keep their enums. The author
 * already hears about it through `query-builder.unresolved-entry`.
 */
it('widens only the list an unresolved entry belongs to', function (string $bucket, array $degraded, array $kept): void {
    $facts = factsWith(function (QueryBuilderFacts $f) use ($bucket): void {
        $f->sorts = [new QbEntry('name', 'default')];
        $f->includes = [new QbEntry('author', 'relationship')];
        $f->fields = [new QbEntry('articles.title', 'field')];
        $f->unresolvedLists = [$bucket => true];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    foreach ($degraded as $name) {
        expect($byName[$name]->schema)->toBe(['type' => 'string'])
            ->and($byName[$name]->style)->toBeNull();
    }

    foreach ($kept as $name) {
        expect($byName[$name]->schema['type'])->toBe('array')
            ->and($byName[$name]->schema['items'])->toHaveKey('enum');
    }
})->with([
    'sorts' => ['sorts', ['sort'], ['include', 'fields[articles]']],
    'includes' => ['includes', ['include'], ['sort', 'fields[articles]']],
    'fields' => ['fields', ['fields[articles]'], ['sort', 'include']],
    // A filter carries no enum of allow-listed names, so nothing widens.
    'filters' => ['filters', [], ['sort', 'include', 'fields[articles]']],
]);

it('widens the deepObject fields properties of a partially recovered allow-list', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->fields = [new QbEntry('articles.title', 'field')];
        $f->unresolvedLists = ['fields' => true];
    });

    $specs = (new QueryBuilderParameters)->build($facts, deepObjectPolicy());

    expect($specs[0]->schema['properties']['articles'])->toBe([
        'type' => 'string',
        'description' => 'Fields to return: title.',
    ]);
});

/**
 * `Count` and `Exists` configured to the same suffix mint ONE value, not two — Spatie collects the
 * generated includes with `unique(getName())`. The count registers first, so the count's wording is
 * what a consumer reads.
 */
it('mints one form when the count and exists suffixes are the same, worded as the count', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->includes = [new QbEntry('customer', 'default')];
    });

    $config = new QueryBuilderConfig(countSuffix: 'Tally', existsSuffix: 'Tally');
    $items = (new QueryBuilderParameters)->build($facts, bracketedPolicy(), $config)[0]->schema['items'];

    expect($items['enum'])->toBe(['customer', 'customerTally'])
        ->and($items['x-enum-descriptions'])->toBe(['', 'Count of related `customer` records.']);
});

it('strips the descending prefix off an allow-listed sort name and dedupes both directions', function (): void {
    // `allowedSorts('-name')` legalizes the base name in both directions — AllowedSort ltrim()s it.
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->sorts = [new QbEntry('-name', 'default'), new QbEntry('name', 'default')];
    });

    $spec = (new QueryBuilderParameters)->build($facts, bracketedPolicy())[0];

    // The description names the base too — "Sort by: -name, name" would restate the convention badly.
    expect($spec->schema['items']['enum'])->toBe(['name', '-name'])
        ->and($spec->description)->toBe('Sort by: name (prefix `-` for descending).');
});

it('expresses include as a comma-serialised enum array under either list style', function (RepresentationPolicy $policy): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->includes = [new QbEntry('author', 'relationship'), new QbEntry('comments', 'relationship')];
    });

    $specs = (new QueryBuilderParameters)->build($facts, $policy);

    expect($specs[0]->name)->toBe('include')
        ->and($specs[0]->style)->toBe('form')
        ->and($specs[0]->explode)->toBeFalse()
        ->and($specs[0]->schema)->toBe([
            'type' => 'array',
            'items' => [
                'type' => 'string',
                'enum' => ['author', 'comments'],
                'x-enum-varnames' => ['Author', 'Comments'],
                'x-enumNames' => ['Author', 'Comments'],
            ],
        ]);
})->with([
    'comma' => [new RepresentationPolicy],
    'array' => [new RepresentationPolicy(listStyle: 'array')],
]);

/**
 * The include enum mirrors Spatie's own allow-list expansion: a bare string legalizes its cumulative
 * relationship partials plus Count/Exists forms for each dot-less partial, a suffixed bare string is
 * that include alone, and a factory-built entry legalizes only its own name.
 */
it('expands the include enum exactly as Spatie legalizes each allow-list entry', function (array $includes, array $enum, ?QueryBuilderConfig $config): void {
    $facts = factsWith(function (QueryBuilderFacts $f) use ($includes): void {
        $f->includes = $includes;
    });

    $specs = (new QueryBuilderParameters)->build($facts, bracketedPolicy(), $config ?? new QueryBuilderConfig);

    expect($specs[0]->schema['items']['enum'])->toBe($enum);
})->with([
    'bare top-level string mints Count and Exists' => [
        [new QbEntry('customer', 'default')],
        ['customer', 'customerCount', 'customerExists'],
        null,
    ],
    'bare nested string mints cumulative partials, suffixes on the dot-less one' => [
        [new QbEntry('posts.comments', 'default')],
        ['posts', 'postsCount', 'postsExists', 'posts.comments'],
        null,
    ],
    'bare string already suffixed is that include alone' => [
        [new QbEntry('customerCount', 'default')],
        ['customerCount'],
        null,
    ],
    'factory entries legalize only their own name' => [
        [new QbEntry('posts.comments', 'relationship'), new QbEntry('postsCount', 'count')],
        ['posts.comments', 'postsCount'],
        null,
    ],
    'bare string already Exists-suffixed is that include alone' => [
        [new QbEntry('customerExists', 'default')],
        ['customerExists'],
        null,
    ],
    // Spatie's Str::endsWith skips empty needles: an empty suffix neither claims bare strings nor
    // mints suffixed forms, and partial expansion still runs.
    'an empty count suffix neither matches nor mints' => [
        [new QbEntry('customer', 'default')],
        ['customer', 'customerExists'],
        new QueryBuilderConfig(countSuffix: ''),
    ],
    'overlapping expansions dedupe keeping first occurrence' => [
        [new QbEntry('posts.comments', 'default'), new QbEntry('posts', 'default')],
        ['posts', 'postsCount', 'postsExists', 'posts.comments'],
        null,
    ],
    'configured suffixes shape the minted forms' => [
        [new QbEntry('customer', 'default')],
        ['customer', 'customerCnt', 'customerHas'],
        new QueryBuilderConfig(countSuffix: 'Cnt', existsSuffix: 'Has'),
    ],
]);

/**
 * Per-value prose, in precedence order: the entry's own comment, then the relation-docblock /
 * `@property` prose a {@see ListValueDescriber} answers, then the approved derived line for a
 * machine-minted Count/Exists form. The value-keyed map is emitted only when every value has prose
 * (Redoc hides values missing from it); the index-parallel array whenever any value does.
 */
it('describes every include value when comment, docblock and derived text cover the set', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->includes = [new QbEntry('entries', 'default')];
    });

    $items = (new QueryBuilderParameters)->build($facts, bracketedPolicy(), describer: almanacDescriber())[0]->schema['items'];

    expect($items['enum'])->toBe(['entries', 'entriesCount', 'entriesExists'])
        ->and($items['x-enumDescriptions'])->toBe([
            'entries' => 'The yearly entries, most recent first.',
            'entriesCount' => 'Count of related `entries` records.',
            'entriesExists' => 'Whether related `entries` records exist.',
        ])
        ->and($items['x-enum-descriptions'])->toBe([
            'The yearly entries, most recent first.',
            'Count of related `entries` records.',
            'Whether related `entries` records exist.',
        ]);
});

it('lets an entry comment beat the relation docblock', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->includes = [(new QbEntry('entries', 'relationship'))->withColumn(null, enumTyped: false, comment: 'Entries, curated for the season.')];
    });

    $items = (new QueryBuilderParameters)->build($facts, bracketedPolicy(), describer: almanacDescriber())[0]->schema['items'];

    expect($items['x-enumDescriptions'])->toBe(['entries' => 'Entries, curated for the season.']);
});

it('describes a dotted entry from its comment and the dot-less partial from its docblock', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->includes = [(new QbEntry('entries.notes', 'default'))->withColumn(null, enumTyped: false, comment: 'Each entry with its margin notes.')];
    });

    $items = (new QueryBuilderParameters)->build($facts, bracketedPolicy(), describer: almanacDescriber())[0]->schema['items'];

    expect($items['enum'])->toBe(['entries', 'entriesCount', 'entriesExists', 'entries.notes'])
        ->and($items['x-enumDescriptions'])->toBe([
            'entries' => 'The yearly entries, most recent first.',
            'entriesCount' => 'Count of related `entries` records.',
            'entriesExists' => 'Whether related `entries` records exist.',
            'entries.notes' => 'Each entry with its margin notes.',
        ]);
});

it('withholds the value-keyed map when one include value has no prose, keeping the gap array', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->includes = [new QbEntry('entries', 'default'), new QbEntry('errata', 'relationship')];
    });

    $items = (new QueryBuilderParameters)->build($facts, bracketedPolicy(), describer: almanacDescriber())[0]->schema['items'];

    expect($items)->not->toHaveKey('x-enumDescriptions')
        ->and($items['x-enum-descriptions'])->toBe([
            'The yearly entries, most recent first.',
            'Count of related `entries` records.',
            'Whether related `entries` records exist.',
            '',
        ]);
});

it('emits no description members at all when nothing has prose', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->includes = [new QbEntry('errata', 'relationship')];
    });

    $items = (new QueryBuilderParameters)->build($facts, bracketedPolicy(), describer: almanacDescriber())[0]->schema['items'];

    expect($items)->not->toHaveKey('x-enumDescriptions')
        ->and($items)->not->toHaveKey('x-enum-descriptions');
});

it('describes sort values from comment or @property prose, marking the descending form', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->sorts = [new QbEntry('title', 'default')];
    });

    $items = (new QueryBuilderParameters)->build($facts, bracketedPolicy(), describer: almanacDescriber())[0]->schema['items'];

    expect($items['x-enumDescriptions'])->toBe([
        'title' => 'The almanac\'s display title.',
        '-title' => 'The almanac\'s display title. (descending)',
    ]);
});

it('lets a sort comment beat the @property prose and gaps an undescribed sibling', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->sorts = [
            (new QbEntry('title', 'default'))->withColumn(null, enumTyped: false, comment: 'Alphabetical.'),
            new QbEntry('issued_at', 'default'),
        ];
    });

    $items = (new QueryBuilderParameters)->build($facts, bracketedPolicy(), describer: almanacDescriber())[0]->schema['items'];

    expect($items)->not->toHaveKey('x-enumDescriptions')
        ->and($items['x-enum-descriptions'])->toBe(['Alphabetical.', 'Alphabetical. (descending)', '', '']);
});

it('emits neither names nor descriptions under a legacy package or the none naming policy', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->sorts = [new QbEntry('title', 'default')];
    });

    $legacy = (new QueryBuilderParameters)->build($facts, bracketedPolicy(), new QueryBuilderConfig(spatieMajor: 6), almanacDescriber())[0];
    $none = (new QueryBuilderParameters)->build($facts, new RepresentationPolicy(enumNaming: 'none'), describer: almanacDescriber())[0];

    expect($legacy->schema)->toBe(['type' => 'string'])
        ->and($none->schema['items'])->not->toHaveKey('x-enum-varnames')
        ->and($none->schema['items'])->not->toHaveKey('x-enumNames')
        // Descriptions are policy-independent — only the NAME hints follow enums.naming.
        ->and($none->schema['items']['x-enumDescriptions'])->toBe([
            'title' => 'The almanac\'s display title.',
            '-title' => 'The almanac\'s display title. (descending)',
        ]);
});

it('expresses each sparse-fieldset group as a comma-serialised enum of its columns', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->fields = [
            new QbEntry('articles.title', 'field'),
            new QbEntry('articles.body', 'field'),
            // A repeated entry dedupes keeping first, exactly as sort/include values do.
            new QbEntry('articles.title', 'field'),
            new QbEntry('author.name', 'field'),
        ];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect(array_keys($byName))->toBe(['fields[articles]', 'fields[author]']);
    expect($byName['fields[articles]']->style)->toBe('form')
        ->and($byName['fields[articles]']->explode)->toBeFalse()
        ->and($byName['fields[articles]']->description)->toBe('Fields to return: title, body.')
        ->and($byName['fields[articles]']->schema['type'])->toBe('array')
        ->and($byName['fields[articles]']->schema['items']['enum'])->toBe(['title', 'body'])
        ->and($byName['fields[articles]']->schema['items']['x-enum-varnames'])->toBe(['Title', 'Body'])
        ->and($byName['fields[author]']->schema['items']['enum'])->toBe(['name']);
});

it('groups a bare field under the unbracketed fields name and describes it from its @property prose', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->fields = [new QbEntry('title', 'field'), new QbEntry('issued_at', 'field')];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy(), describer: almanacDescriber()));

    expect(array_keys($byName))->toBe(['fields']);
    // `issued_at` has no @property prose, so the value-keyed map is withheld and the array carries the gap.
    expect($byName['fields']->schema['items']['enum'])->toBe(['title', 'issued_at'])
        ->and($byName['fields']->schema['items'])->not->toHaveKey('x-enumDescriptions')
        ->and($byName['fields']->schema['items']['x-enum-descriptions'])->toBe(['The almanac\'s display title.', ''])
        ->and($byName['fields']->schema['items']['x-enum-varnames'])->toBe(['Title', 'IssuedAt']);
});

it('lets a fields entry comment beat the column prose, and leaves prefixed groups undescribed', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->fields = [
            (new QbEntry('title', 'field'))->withColumn(null, enumTyped: false, comment: 'The short display title.'),
            new QbEntry('author.title', 'field'),
        ];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy(), describer: almanacDescriber()));

    expect($byName['fields']->schema['items']['x-enumDescriptions'])->toBe(['title' => 'The short display title.'])
        // The author group's `title` is a RELATED table's column; @property prose belongs to the
        // subject model, so the group stays undescribed rather than guessed at.
        ->and($byName['fields[author]']->schema['items'])->not->toHaveKey('x-enumDescriptions')
        ->and($byName['fields[author]']->schema['items'])->not->toHaveKey('x-enum-descriptions');
});

it('splits a fields entry on its last dot, mirroring how Spatie keys the request', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->fields = [new QbEntry('schema.articles.title', 'field')];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect(array_keys($byName))->toBe(['fields[schema.articles]'])
        ->and($byName['fields[schema.articles]']->schema['items']['enum'])->toBe(['title']);
});

it('degrades every sparse-fieldset group like the other lists', function (QueryBuilderConfig $config, array $schema, string $description): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->fields = [new QbEntry('articles.title', 'field'), new QbEntry('articles.body', 'field')];
    });

    $spec = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy(), $config))['fields[articles]'];

    expect($spec->schema)->toBe($schema)
        ->and($spec->description)->toBe($description)
        ->and($spec->style)->toBeNull();
})->with([
    'legacy package' => [
        new QueryBuilderConfig(spatieMajor: 6),
        ['type' => 'string'],
        'Fields to return: title, body.',
    ],
    'custom delimiter' => [
        new QueryBuilderConfig(delimiter: '|'),
        ['type' => 'string'],
        'Fields to return: title, body. Values are separated by `|`.',
    ],
    'empty delimiter selects a single field' => [
        new QueryBuilderConfig(delimiter: ''),
        ['type' => 'string', 'enum' => ['title', 'body'], 'x-enum-varnames' => ['Title', 'Body'], 'x-enumNames' => ['Title', 'Body']],
        'Fields to return: title, body.',
    ],
]);

it('groups sparse fields into a single deepObject fields param whose properties carry the enums', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->fields = [new QbEntry('title', 'field'), new QbEntry('articles.title', 'field'), new QbEntry('author.name', 'field')];
    });

    $specs = (new QueryBuilderParameters)->build($facts, deepObjectPolicy(), describer: almanacDescriber());

    expect($specs)->toHaveCount(1);
    expect($specs[0]->name)->toBe('fields')
        ->and($specs[0]->style)->toBe('deepObject')
        ->and($specs[0]->schema['type'])->toBe('object')
        ->and(array_keys($specs[0]->schema['properties']))->toBe(['_', 'articles', 'author']);

    $bare = $specs[0]->schema['properties']['_'];
    expect($bare['type'])->toBe('array')
        ->and($bare['items']['enum'])->toBe(['title'])
        ->and($bare['items']['x-enumDescriptions'])->toBe(['title' => 'The almanac\'s display title.'])
        ->and($bare['description'])->toBe('Fields to return: title.');
});

it('adds the selector the terminal reads, under the name that terminal was given', function (string $kind, string $terminal, ?array $args, array $expectedNames): void {
    $facts = factsWith(function (QueryBuilderFacts $f) use ($kind, $terminal, $args): void {
        $f->paginates = true;
        $f->paginationKind = $kind;
        $f->paginationTerminal = $terminal;
        $f->paginationArgs = $args;
    });

    expect(array_keys(specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()))))->toBe($expectedNames);
})->with([
    'length' => ['length', 'paginate', [0 => 25], ['page']],
    'simple' => ['simple', 'simplePaginate', [], ['page']],
    'cursor' => ['cursor', 'cursorPaginate', [0 => 50], ['cursor']],
    'a custom terminal, which forwards to paginate($perPage)' => ['length', 'paginateList', [0 => 15], ['page']],
    // The page key the call site renamed — the same rule the resource-collection producer follows, so
    // the two cannot name different keys for one chain.
    'a renamed page' => ['length', 'paginate', [0 => 25, 1 => null, 2 => 'p'], ['p']],
    'a renamed cursor' => ['cursor', 'cursorPaginate', ['cursorName' => 'after'], ['after']],
    // Renamed to something that would not fold: no key at all beats a guessed one.
    'a name that would not fold' => ['length', 'paginate', [0 => 25, 1 => null, 2 => null], []],
    // A spread could have renamed the key, so the same rule applies: no key beats a guessed one.
    'a spread nothing can be indexed off' => ['length', 'paginate', null, []],
]);

it('never claims a page-size key, which the terminal takes from the call site and not from the request', function (array $args): void {
    $facts = factsWith(function (QueryBuilderFacts $f) use ($args): void {
        $f->paginates = true;
        $f->paginationKind = 'length';
        $f->paginationTerminal = 'paginate';
        $f->paginationArgs = $args;
    });

    expect(specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy())))->not->toHaveKey('per_page');
})->with([
    'a bare call' => [[]],
    'a literal size' => [[0 => 25]],
    'a size that would not fold' => [[0 => null]],
]);

it('adds the page-size key the trace proved the endpoint reads, beside the page key', function (?int $default, array $expectedSchema): void {
    $facts = factsWith(function (QueryBuilderFacts $f) use ($default): void {
        $f->paginates = true;
        $f->paginationKind = 'length';
        $f->paginationTerminal = 'paginate';
        $f->paginationArgs = [0 => null];
        $f->pageSize = new RequestPageSizeKey('per_page', $default);
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect(array_keys($byName))->toBe(['page', 'per_page'])
        ->and($byName['per_page']->schema)->toBe($expectedSchema)
        ->and($byName['per_page']->description)->toBe('Number of items per page.');
})->with([
    // Only a fallback the read itself was written with is publishable as a default.
    'a recovered literal fallback' => [15, ['type' => 'integer', 'default' => 15]],
    'a fallback that belongs to a caller' => [null, ['type' => 'integer']],
]);

it('names the app\'s own page-size key, never the one Laravel happens to spell', function (): void {
    // Nothing in the recovery matches on `per_page`: the size argument is the evidence, so an app whose
    // key is `limit` documents `limit`.
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->paginates = true;
        $f->paginationKind = 'length';
        $f->paginationTerminal = 'paginate';
        $f->pageSize = new RequestPageSizeKey('limit');
    });

    expect(array_keys(specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()))))
        ->toBe(['page', 'limit']);
});

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
        ->and($byName['filter[status]']->description)->toBe('Exact match on `status`. Accepts a comma-separated list of values (matched as `whereIn`).');
});

it('models an enum-typed exact filter as an array property under the deepObject policy', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [(new QbEntry('status', 'exact'))->withColumn(enumColumnSchema(), enumTyped: true)];
    });

    $specs = (new QueryBuilderParameters)->build($facts, deepObjectPolicy());

    expect($specs[0]->schema['properties']['status'])->toBe([
        'type' => 'array',
        'items' => enumColumnSchema(),
        'description' => 'Exact match on `status`. Accepts a comma-separated list of values (matched as `whereIn`).',
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
        ->and($byName['filter[active]']->description)->toBe('Exact match on `active`.');
});

/**
 * Every filter kind, typed by nothing (no column resolved), in both policies. A kind whose matching is
 * `LIKE`/column-comparison is a string on the wire and says so; `callback`/`custom` take whatever their
 * user code takes, so they stay untyped rather than pinning a guess a better-informed producer could
 * then only shadow. An unrecognised kind degrades to the string default.
 */
it('leaves an untypable filter untyped only where the kind is user code', function (string $kind, ?string $type): void {
    $facts = factsWith(function (QueryBuilderFacts $f) use ($kind): void {
        $f->filters = [new QbEntry('thing', $kind)];
    });

    $bracketed = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()))['filter[thing]']->schema;
    $property = (new QueryBuilderParameters)->build($facts, deepObjectPolicy())[0]->schema['properties']['thing'];

    expect($bracketed['type'] ?? null)->toBe($type)
        ->and($property['type'] ?? null)->toBe($type)
        // Untyped is not empty: the description still documents the parameter.
        ->and($property['description'] ?? null)->toBeString();
})->with([
    'callback (opaque)' => ['callback', null],
    'custom (opaque)' => ['custom', null],
    'default' => ['default', 'string'],
    'partial' => ['partial', 'string'],
    'exact' => ['exact', 'string'],
    'beginsWithStrict' => ['beginsWithStrict', 'string'],
    'endsWithStrict' => ['endsWithStrict', 'string'],
    'scope' => ['scope', 'string'],
    'operator' => ['operator', 'string'],
    'belongsTo' => ['belongsTo', 'string'],
    'unknown kind' => ['no-such-kind', 'string'],
]);

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
        ->toBe('Exact match on `status`. Accepts a comma-separated list of values (matched as `whereIn`). Accepts `null` to filter for absent values.')
        ->and($byName['filter[status]']->schema['items']['enum'])->toBe(['draft', 'published', 'archived']);
});

it('uses a leading comment as the description, overriding the kind sentence', function (): void {
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

/**
 * Every FILTER_DESCRIPTIONS entry + the unknown-kind degradation. The prose states the contract — what
 * is matched, and on which key a client sends — for a reader who cannot see the codebase, so it carries
 * no package vocabulary; a kind whose matching is user code's states none rather than guess one.
 */
it('describes every filter kind as a contract, degrading an unknown kind to the vague-but-true line', function (string $kind, string $expected): void {
    $facts = factsWith(function (QueryBuilderFacts $f) use ($kind): void {
        $f->filters = [new QbEntry('thing', $kind)];
    });

    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()));

    expect($byName['filter[thing]']->description)->toBe($expected);
})->with([
    'default' => ['default', 'Substring match on `thing`.'],
    'partial' => ['partial', 'Substring match on `thing`.'],
    'exact' => ['exact', 'Exact match on `thing`.'],
    'beginsWithStrict' => ['beginsWithStrict', 'Prefix match on `thing`.'],
    'endsWithStrict' => ['endsWithStrict', 'Suffix match on `thing`.'],
    'scope' => ['scope', 'Filters the result set by `thing`.'],
    'callback' => ['callback', 'Filters the result set by `thing`.'],
    'custom' => ['custom', 'Filters the result set by `thing`.'],
    'operator' => ['operator', 'Compares `thing` against the value.'],
    'belongsTo' => ['belongsTo', 'Matches records belonging to the given `thing`.'],
    'trashed' => ['trashed', 'Soft-delete filter: `with` includes soft-deleted records, `only` returns only soft-deleted; omit to exclude them.'],
    'unknown fallback' => ['wibble', 'Filters the result set by `thing`.'],
]);

/**
 * The dataset above is hand-maintained, so it only proves the rows it lists: this reads the table itself
 * and fails when a kind ships undescribed by it.
 */
it('leaves no filter kind out of the description dataset', function (): void {
    $kinds = array_keys((array) (new ReflectionClass(QueryBuilderParameters::class))->getConstant('FILTER_DESCRIPTIONS'));

    expect($kinds)->toBe([
        'default', 'partial', 'exact', 'beginsWithStrict', 'endsWithStrict',
        'scope', 'callback', 'custom', 'operator', 'trashed', 'belongsTo',
    ])
        // The published set a document's overrides are validated against has to be the same table, or the
        // diagnostic would refuse a kind that works (or accept one that does nothing).
        ->and(QueryBuilderParameters::filterKinds())->toBe($kinds);
});

/**
 * A document may state a kind's lead sentence itself. The semantics are a MERGE over the built-in
 * table, per kind: whatever a document names is overridden and every other kind keeps its default, so
 * naming one sentence never blanks the rest.
 */
it('merges configured kind sentences over the built-in table, kind by kind', function (array $configured, array $expected): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [new QbEntry('status', 'exact'), new QbEntry('email', 'partial'), new QbEntry('flag', 'custom')];
    });

    $config = (new QueryBuilderConfig)->withFilterDescriptions($configured);
    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy(), $config));

    expect([
        $byName['filter[status]']->description,
        $byName['filter[email]']->description,
        $byName['filter[flag]']->description,
    ])->toBe($expected);
})->with([
    'override none' => [[], [
        'Exact match on `status`.',
        'Substring match on `email`.',
        'Filters the result set by `flag`.',
    ]],
    'override one' => [['exact' => 'Matches `%field%` exactly.'], [
        'Matches `status` exactly.',
        'Substring match on `email`.',
        'Filters the result set by `flag`.',
    ]],
    'override several' => [['exact' => 'Matches `%field%` exactly.', 'custom' => 'Narrows by `%field%`.'], [
        'Matches `status` exactly.',
        'Substring match on `email`.',
        'Narrows by `flag`.',
    ]],
    'override every kind these filters use' => [
        ['exact' => 'A. `%field%`', 'partial' => 'B. `%field%`', 'custom' => 'C. `%field%`'],
        ['A. `status`', 'B. `email`', 'C. `flag`'],
    ],
    // A key naming no kind can never match, so nothing moves. `ConfigDiagnostics` is where it is named.
    'a kind nothing has' => [['wibble' => 'Never seen.'], [
        'Exact match on `status`.',
        'Substring match on `email`.',
        'Filters the result set by `flag`.',
    ]],
]);

/**
 * `%field%` is the one token a configured sentence spends, and it spends it on the PUBLIC name exactly
 * as a built-in sentence does. Nothing else is interpolated, so a sentence carrying no token — or one
 * carrying something token-shaped that isn't ours — is published as written.
 */
it('interpolates %field% in a configured sentence, and only that', function (string $sentence, string $expected): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [new QbEntry('status', 'exact', internal: 'status_code')];
    });

    $config = (new QueryBuilderConfig)->withFilterDescriptions(['exact' => $sentence]);
    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy(), $config));

    expect($byName['filter[status]']->description)->toBe($expected);
})->with([
    'once' => ['Matches `%field%` exactly.', 'Matches `status` exactly.'],
    'twice' => ['`%field%` must equal `%field%`.', '`status` must equal `status`.'],
    'a constant sentence, like the trashed default' => ['Only settled invoices.', 'Only settled invoices.'],
    'another token shape is not ours' => ['Matches %column% on `%field%`.', 'Matches %column% on `status`.'],
    'the public name, never the internal column' => ['Filter by `%field%`.', 'Filter by `status`.'],
]);

/**
 * The notes describe the request FORM rather than the matching, so they compose after a configured lead
 * exactly as after a default one — same notes, same order, same sentence-terminating rule.
 */
it('appends every note after a configured lead in the same order as after a default one', function (string $sentence, string $expected): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [new QbEntry(
            'status',
            'exact',
            nullable: true,
            columnSchema: enumColumnSchema(),
            enumTyped: true,
        )];
    });

    $config = (new QueryBuilderConfig)->withFilterDescriptions($sentence === '' ? [] : ['exact' => $sentence]);
    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy(), $config));

    expect($byName['filter[status]']->description)->toBe($expected);
})->with([
    'the default lead' => ['', 'Exact match on `status`. Accepts a comma-separated list of values (matched as `whereIn`). Accepts `null` to filter for absent values.'],
    'a configured lead' => ['Matches `%field%` exactly.', 'Matches `status` exactly. Accepts a comma-separated list of values (matched as `whereIn`). Accepts `null` to filter for absent values.'],
    // The lead is terminated before the notes are appended, whoever wrote it.
    'a configured lead with no full stop' => ['Matches `%field%` exactly', 'Matches `status` exactly. Accepts a comma-separated list of values (matched as `whereIn`). Accepts `null` to filter for absent values.'],
]);

it('composes the custom-separator notes after a configured lead too', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [new QbEntry('status', 'exact', columnSchema: enumColumnSchema(), enumTyped: true)];
    });

    $config = (new QueryBuilderConfig(delimiter: '|'))->withFilterDescriptions(['exact' => 'Matches `%field%` exactly.']);
    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy(), $config));

    expect($byName['filter[status]']->description)->toBe(
        'Matches `status` exactly. Accepts a `|`-separated list of values (matched as `whereIn`). Values: draft, published, archived.',
    );
});

/**
 * A comment describes THIS filter; a configured sentence describes every filter of that kind. So the
 * narrower one still wins, and the configured sentence changes nothing about that ordering.
 */
it('lets an entry comment beat a configured kind sentence', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [
            new QbEntry('status', 'exact', comment: 'The lifecycle stage of the invoice.'),
            new QbEntry('email', 'exact'),
        ];
    });

    $config = (new QueryBuilderConfig)->withFilterDescriptions(['exact' => 'Matches `%field%` exactly.']);
    $byName = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy(), $config));

    expect($byName['filter[status]']->description)->toBe('The lifecycle stage of the invoice.')
        ->and($byName['filter[email]']->description)->toBe('Matches `email` exactly.');
});

/**
 * The feature's floor: with nothing configured, and with an empty bag configured, the parameters are the
 * SAME VALUES — so a document that never sets the key cannot move a byte. Both filter styles, because
 * the deepObject one carries the description on a property instead.
 */
it('emits identical parameters with no configuration and with an empty one', function (RepresentationPolicy $policy): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [
            new QbEntry('status', 'exact', columnSchema: enumColumnSchema(), enumTyped: true),
            new QbEntry('email', 'partial'),
            new QbEntry('flag', 'custom'),
        ];
        $f->sorts = [new QbEntry('name', 'default')];
    });

    $bare = (new QueryBuilderParameters)->build($facts, $policy);
    $empty = (new QueryBuilderParameters)->build($facts, $policy, (new QueryBuilderConfig)->withFilterDescriptions([]));

    expect($empty)->toEqual($bare);
})->with([
    'bracketed' => [bracketedPolicy()],
    'deepObject' => [deepObjectPolicy()],
]);

/**
 * Determinism: the same configured sentences produce the same bytes every run, and the ORDER the
 * document happened to write them in is not one of the inputs.
 */
it('builds the same description whatever order the configured kinds were written in', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [new QbEntry('status', 'exact'), new QbEntry('email', 'partial')];
    });

    $descriptions = static function (array $configured) use ($facts): array {
        $config = (new QueryBuilderConfig)->withFilterDescriptions($configured);

        return array_map(
            static fn (QueryParameterSpec $spec): ?string => $spec->description,
            specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy(), $config)),
        );
    };

    $forwards = $descriptions(['exact' => 'Is `%field%`.', 'partial' => 'Contains `%field%`.']);
    $backwards = $descriptions(['partial' => 'Contains `%field%`.', 'exact' => 'Is `%field%`.']);

    expect($forwards)->toBe(['filter[status]' => 'Is `status`.', 'filter[email]' => 'Contains `email`.'])
        ->and($backwards)->toBe($forwards)
        ->and($descriptions(['exact' => 'Is `%field%`.', 'partial' => 'Contains `%field%`.']))->toBe($forwards);
});

/**
 * The public name is the contract; the internal column is not something a consumer can see or send.
 */
it('names the public filter key in the prose, never the internal column', function (): void {
    $facts = factsWith(function (QueryBuilderFacts $f): void {
        $f->filters = [new QbEntry('status', 'exact', internal: 'status_code')];
    });

    $description = specsByName((new QueryBuilderParameters)->build($facts, bracketedPolicy()))['filter[status]']->description;

    expect($description)->toBe('Exact match on `status`.')
        ->and($description)->not->toContain('status_code');
});
