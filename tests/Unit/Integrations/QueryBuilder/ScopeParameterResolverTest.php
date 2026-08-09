<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\QueryBuilder\FilterColumn;
use Docuccino\Laravel\Integrations\QueryBuilder\ScopeParameterResolver;
use Workbench\App\Enums\WidgetStatus;
use Workbench\App\Models\Gadget;

/**
 * A scope filter (`AllowedFilter::scope('minScore')`) types off the model scope method's VALUE
 * parameter (the second, after `Builder $query`). Dataset over the backed-enum, native-scalar,
 * no-parameter and unresolvable shapes — pure reflection, no engine.
 */
it('resolves the scope value parameter type to a filter column', function (string $filter, string $kind, ?string $enum, ?array $scalar): void {
    $column = (new ScopeParameterResolver)->resolve(Gadget::class, $filter);

    expect($column->kind)->toBe($kind)
        ->and($column->enum)->toBe($enum)
        ->and($column->scalarSchema)->toBe($scalar);
})->with([
    'backed enum value → enum' => ['status', FilterColumn::KIND_ENUM, WidgetStatus::class, null],
    'native int value → scalar' => ['minScore', FilterColumn::KIND_SCALAR, null, ['type' => 'integer']],
    'no value parameter → none' => ['popular', FilterColumn::KIND_NONE, null, null],
    'unknown scope method → none' => ['missing', FilterColumn::KIND_NONE, null, null],
]);

it('records the enum declaring file as a dependency for a backed-enum scope value', function (): void {
    $column = (new ScopeParameterResolver)->resolve(Gadget::class, 'status');

    expect($column->isEnum())->toBeTrue()
        ->and(array_map('basename', $column->dependencyFiles))->toContain('WidgetStatus.php');
});

it('degrades an unresolvable model to none', function (): void {
    expect((new ScopeParameterResolver)->resolve('App\\Nope', 'status')->kind)->toBe(FilterColumn::KIND_NONE);
});
