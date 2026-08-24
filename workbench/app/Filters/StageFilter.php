<?php

declare(strict_types=1);

namespace Workbench\App\Filters;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * A shared custom filter whose class-level `#[QueryParameter]` carries a description AND a default, so
 * both are contestable by what one call site writes: a comment above the entry, a chained `->default()`.
 *
 * @implements Filter<Model>
 */
#[QueryParameter(name: 'ignored', type: 'string', description: 'The lifecycle stage.', default: 'sent')]
final class StageFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->where($property, $value);
    }

    /** The factory every call site uses, wrapping the Spatie registration in one place. */
    public static function allowed(string $key, ?string $column = null): AllowedFilter
    {
        return AllowedFilter::custom($key, new self, $column ?? $key);
    }
}
