<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A model that narrows the query every binding of it runs onto a column of its choosing, and finds its
 * scoped children its own way too. Never queried.
 *
 * @property int $id
 * @property string $public_id
 */
final class Coupon extends Model
{
    protected $guarded = [];

    public function resolveRouteBindingQuery($query, $value, $field = null): Builder
    {
        return $query->where('public_id', $value);
    }

    public function resolveChildRouteBinding($childType, $value, $field): ?Model
    {
        return $this->entries()->where('reference', $value)->first();
    }

    /** The forms filed under this coupon. */
    public function entries(): HasMany
    {
        return $this->hasMany(Form::class);
    }
}
