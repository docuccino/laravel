<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\IgnoreParam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Workbench\App\Http\Requests\FilterWindowRequest;
use Workbench\App\Models\Gadget;

/**
 * A Query Builder list whose filters the deepObject representation publishes as MEMBERS of one `filter`
 * object rather than as parameters of their own, with an `#[IgnoreParam]` naming one of them the way an
 * author writes it: the bracketed wire name. One action drops members that exist — one of them required
 * by the application's own rules, one of them nested below another — one names a member nobody
 * publishes, which is the half that has to be reported, and one names a member twice. Routed ad-hoc, so
 * no committed golden churns.
 */
final class IgnoredMembersController
{
    /**
     * List gadgets. The `opaque` filter is the application's own business and not for publication; the
     * date window is published, less the bound the document has no business naming.
     *
     * @return LengthAwarePaginator<int, Gadget>
     */
    #[IgnoreParam(name: 'filter[opaque]', in: 'query')]
    #[IgnoreParam(name: 'filter[window][from]', in: 'query')]
    public function members(FilterWindowRequest $request): LengthAwarePaginator
    {
        return $this->gadgets();
    }

    /**
     * The same surface with a misspelled member. Nothing is dropped, so the filter the author meant to
     * hide is published — which is why the declaration is reported rather than left silent.
     *
     * @return LengthAwarePaginator<int, Gadget>
     */
    #[IgnoreParam(name: 'filter[opaqu]', in: 'query')]
    public function typo(FilterWindowRequest $request): LengthAwarePaginator
    {
        return $this->gadgets();
    }

    /**
     * The same member named twice, in two spellings that do not dedupe. Both did their job: which
     * declarations matched is decided against the members standing before ANY of them removed anything,
     * or the second would report the member the first had just taken away as one that was never there.
     *
     * @return LengthAwarePaginator<int, Gadget>
     */
    #[IgnoreParam(name: 'filter[opaque]')]
    #[IgnoreParam(name: 'filter[opaque]', in: 'query')]
    public function repeated(FilterWindowRequest $request): LengthAwarePaginator
    {
        return $this->gadgets();
    }

    /**
     * @return LengthAwarePaginator<int, Gadget>
     */
    private function gadgets(): LengthAwarePaginator
    {
        return QueryBuilder::for(Gadget::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::callback('opaque', static function (Builder $query, mixed $value): void {
                    $query->whereRaw('1 = 1');
                }),
                AllowedFilter::callback('window', static function (Builder $query, mixed $value): void {
                    $query->whereBetween('starts_at', (array) $value);
                }),
            ])
            ->paginate(20);
    }
}
