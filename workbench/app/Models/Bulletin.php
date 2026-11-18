<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A ulid-keyed model whose binding resolves published bulletins only, and still looks the segment up on
 * the column the framework's own lookup would. Never queried.
 *
 * @property string $id
 * @property bool $published
 */
final class Bulletin extends Model
{
    use HasUlids;

    protected $guarded = [];

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return self::query()
            ->where('published', true)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }
}
