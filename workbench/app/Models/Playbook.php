<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A uuid-keyed model whose binding admits the signed-in owner's playbooks and the shared ones, and still
 * looks the segment up on the column the framework's own lookup would. Never queried.
 *
 * @property string $id
 * @property string $slug
 * @property int|null $owner_id
 */
final class Playbook extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function resolveRouteBinding($value, $field = null): self
    {
        return self::query()
            ->where(fn (Builder $query) => $query->where('owner_id', auth()->id())->orWhereNull('owner_id'))
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->firstOrFail();
    }
}
