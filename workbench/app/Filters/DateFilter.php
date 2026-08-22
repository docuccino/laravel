<?php

declare(strict_types=1);

namespace Workbench\App\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * A GENERIC reusable Spatie custom filter: its `__invoke` body filters on the `$property` Spatie hands
 * it, so there is no literal column to read out of the body and no attribute to override — the declared
 * internal name on the `AllowedFilter::custom(...)` call is the only column binding there is.
 *
 * @implements Filter<Model>
 */
final class DateFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->whereDate($property, is_string($value) ? $value : '');
    }
}
