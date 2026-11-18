<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A model routed on its reference whose binding upper-cases the segment before matching it, so the value
 * a client sends is not the one the column holds. Never queried.
 *
 * @property int $id
 * @property string $reference
 */
final class Consignment extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return self::query()->where($field ?? $this->getRouteKeyName(), Str::upper($value))->first();
    }
}
