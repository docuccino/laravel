<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderConfig;

/**
 * The config value object is the only place the package's (renamable) request parameter names are
 * read from its config bag. Covered dataset-driven: full recovery of every renamed key, the absent-bag
 * default fallback (`recovered = false`, so the extension emits an info diagnostic), and per-key
 * degradation to defaults for absent or ill-typed values.
 */
it('falls back to package defaults and marks itself unrecovered when the config bag is absent', function (): void {
    $config = QueryBuilderConfig::fromArray([]);

    expect($config->recovered)->toBeFalse()
        ->and($config->filter)->toBe('filter')
        ->and($config->sort)->toBe('sort')
        ->and($config->include)->toBe('include')
        ->and($config->fields)->toBe('fields');
});

it('reads every renamable parameter key from a published config bag and marks itself recovered', function (): void {
    $config = QueryBuilderConfig::fromArray([
        'parameters' => [
            'filter' => 'f',
            'sort' => 's',
            'include' => 'inc',
            'fields' => 'flds',
        ],
    ]);

    expect($config->recovered)->toBeTrue()
        ->and($config->filter)->toBe('f')
        ->and($config->sort)->toBe('s')
        ->and($config->include)->toBe('inc')
        ->and($config->fields)->toBe('flds');
});

it('degrades ill-typed or empty parameter names to the package defaults, staying recovered', function (): void {
    $config = QueryBuilderConfig::fromArray([
        'parameters' => [
            'filter' => '',    // empty string → default
            'sort' => 123,     // non-string → default
            'include' => null, // null → default
        ],
    ]);

    expect($config->recovered)->toBeTrue()
        ->and($config->filter)->toBe('filter')
        ->and($config->sort)->toBe('sort')
        ->and($config->include)->toBe('include');
});

it('treats a present-but-parameterless bag as recovered on defaults', function (): void {
    $config = QueryBuilderConfig::fromArray(['disable_invalid_filter_query_exception' => true]);

    expect($config->recovered)->toBeTrue()
        ->and($config->filter)->toBe('filter');
});

it('is strict by default and only relaxes when every invalid-query exception is disabled', function (): void {
    // Package default (no bag) and a bag with the exceptions enabled → strict, so a 400 is documented.
    expect(QueryBuilderConfig::fromArray([])->strict)->toBeTrue()
        ->and(QueryBuilderConfig::fromArray(['parameters' => ['filter' => 'f']])->strict)->toBeTrue()
        // Disabling only one exception leaves strict on (the others still throw a 400).
        ->and(QueryBuilderConfig::fromArray(['disable_invalid_filter_query_exception' => true])->strict)->toBeTrue()
        // All three disabled → not strict, so no 400 is documented.
        ->and(QueryBuilderConfig::fromArray([
            'disable_invalid_filter_query_exception' => true,
            'disable_invalid_sort_query_exception' => true,
            'disable_invalid_include_query_exception' => true,
        ])->strict)->toBeFalse();
});

/**
 * v7 renamed the invalid-include key to the singular form. Which spelling counts is the INSTALLED
 * major's, not either-or: a v6 config file left behind by an upgrade still carries the plural key and
 * v7 ignores it, so honouring it would document no 400 where the server throws one.
 */
it('reads the invalid-include exception key the installed major uses', function (int $major, string $key, bool $strict): void {
    expect(QueryBuilderConfig::fromArray([
        'disable_invalid_filter_query_exception' => true,
        'disable_invalid_sort_query_exception' => true,
        $key => true,
    ], $major)->strict)->toBe($strict);
})->with([
    'v7 honors the singular key' => [7, 'disable_invalid_include_query_exception', false],
    'v7 ignores the v6 plural key' => [7, 'disable_invalid_includes_query_exception', true],
    'v6 honors the plural key' => [6, 'disable_invalid_includes_query_exception', false],
    'v6 ignores the v7 singular key' => [6, 'disable_invalid_include_query_exception', true],
]);

it('reads the include Count/Exists suffixes, degrading each to the package default', function (): void {
    // Absent bag and absent/ill-typed entries → Spatie's defaults; published values are honored.
    expect(QueryBuilderConfig::fromArray([])->countSuffix)->toBe('Count')
        ->and(QueryBuilderConfig::fromArray([])->existsSuffix)->toBe('Exists')
        ->and(QueryBuilderConfig::fromArray(['suffixes' => ['count' => 'Cnt', 'exists' => 'Has']])->countSuffix)->toBe('Cnt')
        ->and(QueryBuilderConfig::fromArray(['suffixes' => ['count' => 'Cnt', 'exists' => 'Has']])->existsSuffix)->toBe('Has')
        ->and(QueryBuilderConfig::fromArray(['suffixes' => ['count' => 123]])->countSuffix)->toBe('Count')
        ->and(QueryBuilderConfig::fromArray(['suffixes' => 'nope'])->existsSuffix)->toBe('Exists')
        // An empty suffix is a value Spatie honors (Str::endsWith skips empty needles), not an omission.
        ->and(QueryBuilderConfig::fromArray(['suffixes' => ['count' => '']])->countSuffix)->toBe('');
});

it('reads the value delimiter, keeping an empty one and degrading an ill-typed one', function (): void {
    // Spatie splits list values on `query-builder.delimiter`; `''` means no splitting and is honored
    // as written, so only an absent or ill-typed entry falls back to the comma.
    expect(QueryBuilderConfig::fromArray([])->delimiter)->toBe(',')
        ->and(QueryBuilderConfig::fromArray([])->splitsOnComma())->toBeTrue()
        ->and(QueryBuilderConfig::fromArray(['delimiter' => '|'])->delimiter)->toBe('|')
        ->and(QueryBuilderConfig::fromArray(['delimiter' => '|'])->splitsOnComma())->toBeFalse()
        ->and(QueryBuilderConfig::fromArray(['delimiter' => ''])->delimiter)->toBe('')
        ->and(QueryBuilderConfig::fromArray(['delimiter' => 123])->delimiter)->toBe(',');
});

it('brackets filter and fields member keys under the effective parameter names', function (): void {
    $config = QueryBuilderConfig::fromArray(['parameters' => ['filter' => 'f', 'fields' => 'flds']]);

    expect($config->filterKey('status'))->toBe('f[status]')
        ->and($config->fieldsKey('articles'))->toBe('flds[articles]');
});

it('parses the installed package major off a composer version string, reading anything unreadable as current', function (?string $version, int $major): void {
    // Composer is the only supported install path, so a runtime API that can't answer (or a dev
    // checkout) is a harness artifact — treated as the supported major, never as an old install.
    expect(QueryBuilderConfig::majorOf($version))->toBe($major);
})->with([
    'plain semver' => ['7.3.3', 7],
    'v-prefixed' => ['v7.0.0', 7],
    'v6' => ['6.4.4', 6],
    'v5' => ['5.8.1', 5],
    'runtime API silent' => [null, 7],
    'dev branch' => ['dev-main', 7],
]);

it('threads the detected major through fromArray, the empty bag included', function (): void {
    expect(QueryBuilderConfig::fromArray([], 6)->spatieMajor)->toBe(6)
        ->and(QueryBuilderConfig::fromArray([], 6)->recovered)->toBeFalse()
        ->and(QueryBuilderConfig::fromArray(['delimiter' => ','], 6)->spatieMajor)->toBe(6)
        ->and(QueryBuilderConfig::fromArray([])->spatieMajor)->toBe(7);
});

it('treats only majors below the supported one as legacy', function (int $major, bool $legacy): void {
    expect((new QueryBuilderConfig(spatieMajor: $major))->legacyPackage())->toBe($legacy);
})->with([
    'v5' => [5, true],
    'v6' => [6, true],
    'v7' => [7, false],
    'a future major' => [8, false],
]);

/**
 * The one predicate the list emitter and the collision report share. Every combination that drops the
 * enum has to answer false here, or the report claims member names the document never published.
 */
it('mints names only where an allow-list is published as an enum', function (QueryBuilderConfig $config, bool $mints): void {
    expect($config->mintsNames())->toBe($mints);
})->with([
    'the defaults' => [new QueryBuilderConfig, true],
    'no splitting at all still enums one value' => [new QueryBuilderConfig(delimiter: ''), true],
    'a custom delimiter degrades to a plain string' => [new QueryBuilderConfig(delimiter: '|'), false],
    'a pre-v7 package degrades to a plain string' => [new QueryBuilderConfig(spatieMajor: 6), false],
    'both at once' => [new QueryBuilderConfig(delimiter: '|', spatieMajor: 6), false],
]);

/**
 * `filter_descriptions` is the one member that comes from Docuccino's own per-document bag rather than
 * the package's, so it arrives through a seam of its own. What lands here is data: strings keyed by
 * whatever the document wrote, every non-string sentence dropped (a description is a string) and every
 * key kept as configured — an unknown one simply never matches a filter, and `ConfigDiagnostics` names
 * it rather than this silently rewriting somebody's config.
 */
it('keeps the string sentences a document configured, dropping the rest', function (mixed $configured, array $expected): void {
    expect((new QueryBuilderConfig)->withFilterDescriptions($configured)->filterDescriptions)->toBe($expected);
})->with([
    'nothing configured' => [null, []],
    'an empty bag' => [[], []],
    'one kind' => [['exact' => 'Matches `%field%` exactly.'], ['exact' => 'Matches `%field%` exactly.']],
    'several kinds, in config order' => [
        ['partial' => 'Contains `%field%`.', 'exact' => 'Is `%field%`.'],
        ['partial' => 'Contains `%field%`.', 'exact' => 'Is `%field%`.'],
    ],
    'a key naming no kind is kept as configured' => [['wibble' => 'Hm.'], ['wibble' => 'Hm.']],
    'a non-string sentence is dropped' => [['exact' => ['nope'], 'partial' => 'Contains `%field%`.'], ['partial' => 'Contains `%field%`.']],
    'a scalar sentence is not coerced' => [['exact' => 42], []],
    'a whole non-array bag' => ['Exact match.', []],
]);

it('leaves every other member of the config alone when the descriptions are threaded on', function (): void {
    $config = QueryBuilderConfig::fromArray(['parameters' => ['filter' => 'f'], 'delimiter' => '|'], 6);
    $with = $config->withFilterDescriptions(['exact' => 'Is `%field%`.']);

    expect($with->filter)->toBe('f')
        ->and($with->delimiter)->toBe('|')
        ->and($with->spatieMajor)->toBe(6)
        ->and($with->recovered)->toBeTrue()
        ->and($config->filterDescriptions)->toBe([]);
});
