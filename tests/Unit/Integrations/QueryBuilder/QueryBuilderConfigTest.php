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

it('brackets filter and fields member keys under the effective parameter names', function (): void {
    $config = QueryBuilderConfig::fromArray(['parameters' => ['filter' => 'f', 'fields' => 'flds']]);

    expect($config->filterKey('status'))->toBe('f[status]')
        ->and($config->fieldsKey('articles'))->toBe('flds[articles]');
});
