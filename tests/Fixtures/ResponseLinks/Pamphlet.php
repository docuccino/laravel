<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Illuminate\Database\Eloquent\Model;

/**
 * A model whose binding resolves published pamphlets only, and still looks the segment up on its route
 * key as the framework does. Never queried.
 *
 * @property int $id
 * @property bool $published
 */
final class Pamphlet extends Model
{
    protected $guarded = [];

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return self::query()->where('published', true)->where($field ?? $this->getRouteKeyName(), $value)->first();
    }
}
