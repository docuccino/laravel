<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model whose binding resolves unarchived memos only, always on its route key: a column a route names
 * is never the one looked up. Never queried.
 *
 * @property int $id
 * @property string $title
 * @property bool $archived
 */
final class Memo extends Model
{
    protected $guarded = [];

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return self::query()
            ->where('archived', false)
            ->where($this->getRouteKeyName(), $value)
            ->first();
    }
}
