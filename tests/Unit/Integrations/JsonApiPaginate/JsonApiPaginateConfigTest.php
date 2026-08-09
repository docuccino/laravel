<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateConfig;

/**
 * The config value object is the only place the package's (renamable) parameter names + sizes are
 * read from its config bag. Covered dataset-driven: full recovery, the absent-bag default fallback
 * (`recovered = false`), the mode-derivation table, and per-key degradation to defaults for absent or
 * ill-typed values.
 */
it('falls back to package defaults and marks itself unrecovered when the config bag is absent', function (): void {
    $config = JsonApiPaginateConfig::fromArray([]);

    expect($config->recovered)->toBeFalse()
        ->and($config->pageParameter)->toBe('page')
        ->and($config->numberParameter)->toBe('number')
        ->and($config->sizeParameter)->toBe('size')
        ->and($config->cursorParameter)->toBe('cursor')
        ->and($config->methodName)->toBe('jsonPaginate')
        ->and($config->defaultSize)->toBe(30)
        ->and($config->maxResults)->toBe(30)
        ->and($config->mode)->toBe(JsonApiPaginateConfig::MODE_LENGTH);
});

it('reads every renamable key from a published config bag and marks itself recovered', function (): void {
    $config = JsonApiPaginateConfig::fromArray([
        'pagination_parameter' => 'p',
        'number_parameter' => 'num',
        'size_parameter' => 'sz',
        'cursor_parameter' => 'cur',
        'method_name' => 'apiPaginate',
        'default_size' => 25,
        'max_results' => 100,
    ]);

    expect($config->recovered)->toBeTrue()
        ->and($config->pageParameter)->toBe('p')
        ->and($config->numberParameter)->toBe('num')
        ->and($config->sizeParameter)->toBe('sz')
        ->and($config->cursorParameter)->toBe('cur')
        ->and($config->methodName)->toBe('apiPaginate')
        ->and($config->defaultSize)->toBe(25)
        ->and($config->maxResults)->toBe(100);
});

it('derives the pagination mode from the package toggles', function (array $bag, string $expected): void {
    // A non-empty bag keeps the toggle keys meaningful; a bare toggle still reads the rest as defaults.
    expect(JsonApiPaginateConfig::fromArray($bag + ['default_size' => 30])->mode)->toBe($expected);
})->with([
    'cursor wins' => [['use_cursor_pagination' => true, 'use_simple_pagination' => true], JsonApiPaginateConfig::MODE_CURSOR],
    'simple' => [['use_simple_pagination' => true], JsonApiPaginateConfig::MODE_SIMPLE],
    'length (default)' => [[], JsonApiPaginateConfig::MODE_LENGTH],
]);

it('degrades ill-typed or non-positive config values to the package defaults', function (): void {
    $config = JsonApiPaginateConfig::fromArray([
        'pagination_parameter' => '',   // empty string → default
        'number_parameter' => 123,      // non-string → default
        'default_size' => 0,            // non-positive → default
        'max_results' => -5,            // negative → default
    ]);

    expect($config->recovered)->toBeTrue()
        ->and($config->pageParameter)->toBe('page')
        ->and($config->numberParameter)->toBe('number')
        ->and($config->defaultSize)->toBe(30)
        ->and($config->maxResults)->toBe(30);
});

it('brackets a member key under the page parameter', function (): void {
    expect(JsonApiPaginateConfig::fromArray(['pagination_parameter' => 'page'])->bracket('number'))->toBe('page[number]');
});
