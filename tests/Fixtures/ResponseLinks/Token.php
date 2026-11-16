<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Illuminate\Database\Eloquent\Model;

/**
 * A model that hands out an encoded key and decodes it when a route binds one, the hashid shape. Never
 * queried.
 *
 * @property int $id
 * @property string $code
 */
final class Token extends Model
{
    protected $guarded = [];

    public function getRouteKey(): string
    {
        return base64_encode((string) $this->getKey());
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return self::query()->whereKey(base64_decode((string) $value))->first();
    }
}
