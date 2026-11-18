<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\QueryBuilder;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Workbench\App\Filters\ArchivedSinceFilter;
use Workbench\App\Models\Gadget;

/**
 * Filters a null reaches, each the way an application lets one through: `nullable()` on an exact match, on
 * a custom filter and on a callback that refuses an empty term itself, and `default(null)`, which Spatie
 * makes nullable too, on an exact match and on a partial one.
 */
final class NullableFiltersController
{
    /**
     * @return LengthAwarePaginator<int, Gadget>
     */
    public function index(): LengthAwarePaginator
    {
        return QueryBuilder::for(Gadget::class)
            ->allowedFilters([
                AllowedFilter::exact('status')->nullable(),
                AllowedFilter::exact('unset_status', 'status')->default(null),
                AllowedFilter::partial('public_id')->default(null),
                AllowedFilter::custom('archived_since', new ArchivedSinceFilter)->nullable(),
                AllowedFilter::callback('term', static function (Builder $query, mixed $value): void {
                    $term = trim(is_string($value) ? $value : '');
                    abort_if($term === '', 422, 'An empty or whitespace-only term is rejected.');
                    $query->where('public_id', 'like', $term.'%');
                })->nullable(),
            ])
            ->paginate(20);
    }
}
