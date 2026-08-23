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
        ->and($config->fields)->toBe('fields')
        ->and($config->append)->toBe('append');
});

it('reads every renamable parameter key from a published config bag and marks itself recovered', function (): void {
    $config = QueryBuilderConfig::fromArray([
        'parameters' => [
            'filter' => 'f',
            'sort' => 's',
            'include' => 'inc',
            'fields' => 'flds',
            'append' => 'app',
        ],
    ]);

    expect($config->recovered)->toBeTrue()
        ->and($config->filter)->toBe('f')
        ->and($config->sort)->toBe('s')
        ->and($config->include)->toBe('inc')
        ->and($config->fields)->toBe('flds')
        ->and($config->append)->toBe('app');
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
            'disable_invalid_includes_query_exception' => true,
        ])->strict)->toBeFalse();
});

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
