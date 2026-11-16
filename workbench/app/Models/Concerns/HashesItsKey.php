<?php

declare(strict_types=1);

namespace Workbench\App\Models\Concerns;

/**
 * The hashid shape: a model hands out an encoded key and decodes it when a route binds one, so a segment
 * holds the code, never the key's own column.
 */
trait HashesItsKey
{
    public function getRouteKey(): string
    {
        return base64_encode((string) $this->getKey());
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->whereKey(base64_decode((string) $value))->first();
    }
}
