<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Workbench\App\Http\Requests\FilterBoundsRequest;
use Workbench\App\Models\Gadget;

/**
 * A Query Builder list endpoint whose filters are ALL applied by the application's own closures, so the
 * integration can type none of them — and two of them are typed anyway, one by the action's own
 * attribute and one by a validation rule recovered a phase later. The third is typed by nothing, which
 * is the only one the untyped-filter report is about. Routed ad-hoc, so no committed golden churns.
 */
final class AppFilteredListController
{
    /**
     * List gadgets, with a free-text search, a maker lookup and a date window.
     *
     * @return LengthAwarePaginator<int, Gadget>
     */
    #[QueryParameter('filter[label]', type: 'string', description: 'The maker to list gadgets for.')]
    public function index(FilterBoundsRequest $request): LengthAwarePaginator
    {
        return QueryBuilder::for(Gadget::class)
            ->allowedFilters([
                AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                    $query->where(static function (Builder $inner) use ($value): void {
                        $inner->where('name', 'like', '%'.$value.'%')
                            ->orWhere('status', 'like', '%'.$value.'%');
                    });
                }),
                AllowedFilter::callback('label', static function (Builder $query, mixed $value): void {
                    $query->whereHas('maker', static function (Builder $maker) use ($value): void {
                        $maker->where('name', $value);
                    });
                }),
                AllowedFilter::callback('min_days', static function (Builder $query, mixed $value): void {
                    $query->whereDate('starts_at', '>=', now()->subDays((int) $value))
                        ->orderByDesc('starts_at');
                }),
            ])
            ->paginate(20);
    }
}
