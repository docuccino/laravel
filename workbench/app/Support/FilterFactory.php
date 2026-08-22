<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * A user-land filter factory (the recurring `ListFilters`-style idiom): each static returns a Spatie
 * `AllowedFilter` built from a single unconditional `AllowedFilter::callback(...)`, validating the
 * value and constraining the query. It exists so the QB integration can recover the filter's typing
 * from the CALL SITE — a backed-enum class-string argument names the value domain, and the filter's
 * own key is the column — WITHOUT descending into this body (mirroring a real app's convention of
 * keeping a single unconditional return so a static analyzer can resolve it). Only ever analysed.
 */
final class FilterFactory
{
    /**
     * A backed-enum filter: the value must be a case of `$enumClass`, matched against `$column` (the
     * key by default).
     *
     * @param  class-string<BackedEnum>  $enumClass
     */
    public static function enum(string $key, string $enumClass, ?string $column = null): AllowedFilter
    {
        return AllowedFilter::callback($key, static function (Builder $query, mixed $value) use ($key, $enumClass, $column): void {
            $case = is_string($value) ? $enumClass::tryFrom($value) : null;

            $query->where($column ?? $key, $case ?? throw ValidationException::withMessages([
                "filter.{$key}" => 'Invalid value.',
            ]));
        });
    }

    /** A boolean filter over `$column` (the key by default). */
    public static function boolean(string $key, ?string $column = null): AllowedFilter
    {
        return AllowedFilter::callback($key, static function (Builder $query, mixed $value) use ($column, $key): void {
            $query->where($column ?? $key, filter_var($value, FILTER_VALIDATE_BOOLEAN));
        });
    }

    /** A UUID-equality filter over `$column` (the key by default). */
    public static function uuid(string $key, ?string $column = null): AllowedFilter
    {
        return AllowedFilter::callback($key, static function (Builder $query, mixed $value) use ($column, $key): void {
            $query->where($column ?? $key, is_string($value) ? $value : '');
        });
    }

    /** A date filter over `$column` (the key by default). */
    public static function date(string $key, ?string $column = null): AllowedFilter
    {
        return AllowedFilter::callback($key, static function (Builder $query, mixed $value) use ($column, $key): void {
            $query->whereDate($column ?? $key, is_string($value) ? $value : '');
        });
    }

    /**
     * A free-text partial search across several columns — no single column, so it stays a plain string.
     *
     * @param  list<string>  $columns
     */
    public static function search(string $key, array $columns): AllowedFilter
    {
        return AllowedFilter::callback($key, static function (Builder $query, mixed $value) use ($columns): void {
            $query->where(static function (Builder $inner) use ($columns, $value): void {
                foreach ($columns as $column) {
                    $inner->orWhere($column, 'like', '%'.(is_string($value) ? $value : '').'%');
                }
            });
        });
    }
}
