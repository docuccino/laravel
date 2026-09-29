<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * A model whose `Attribute` accessors write their `get:` closure with `static` on a line of its own, or
 * with an attribute above it: the closure's declaration starts a line before its keyword. Only ever
 * reflected.
 *
 * @property string $title The lectern's title.
 */
final class Lectern extends Model
{
    /**
     * @return Attribute<string, never>
     */
    public function heading(): Attribute
    {
        return Attribute::make(get: static fn (mixed $value, array $attributes): string => (string) ($attributes['title'] ?? ''));
    }

    /**
     * @return Attribute<string, never>
     */
    public function slug(): Attribute
    {
        return Attribute::make(get: #[\Deprecated]
            fn (mixed $value, array $attributes): string => (string) ($attributes['title'] ?? ''));
    }
}
