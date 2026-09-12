<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Workbench\App\Http\Requests\FilterNestedRequiredRequest;
use Workbench\App\Http\Requests\FilterRequiredMemberRequest;
use Workbench\App\Models\Gadget;

/**
 * Query Builder list actions under the deepObject representation, where the filters are MEMBERS of one
 * `filter` object and requiredness therefore belongs to that object's list rather than to a parameter
 * of its own. Three ways the server's answer and the document's have to agree: two producers at two
 * layers each requiring a different member, a requirement two levels down with no required sibling
 * above it, and an author overriding the container's own requiredness. Routed ad-hoc, so no committed
 * golden churns.
 */
final class DeepObjectRequiredController
{
    /**
     * List gadgets. `status` is required by the declaration and `min_days` by the application's own
     * rules — two producers, two layers, one list.
     *
     * @return LengthAwarePaginator<int, Gadget>
     */
    #[QueryParameter(name: 'filter[status]', required: true)]
    public function contested(FilterRequiredMemberRequest $request): LengthAwarePaginator
    {
        return $this->gadgets();
    }

    /**
     * The same surface whose only requirement is the start of the window — a member of a member.
     *
     * @return LengthAwarePaginator<int, Gadget>
     */
    public function nested(FilterNestedRequiredRequest $request): LengthAwarePaginator
    {
        return $this->gadgets();
    }

    /**
     * The same required member, under an author who states the container itself optional: send no
     * filter at all and the server is content, send one and it must carry `min_days`.
     *
     * @return LengthAwarePaginator<int, Gadget>
     */
    #[QueryParameter(name: 'filter', required: false)]
    public function optional(FilterRequiredMemberRequest $request): LengthAwarePaginator
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
                AllowedFilter::exact('min_days'),
                AllowedFilter::callback('window', static function (Builder $query, mixed $value): void {
                    $query->whereBetween('starts_at', (array) $value);
                }),
            ])
            ->paginate(20);
    }
}
