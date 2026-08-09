<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * A custom Eloquent caster (not a native cast, not an enum) — the filter-column resolver leaves a
 * column cast by it a plain string, since its wire shape is not statically knowable.
 *
 * @implements CastsAttributes<string, string>
 */
final class CustomCaster implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get($model, string $key, $value, array $attributes): string
    {
        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set($model, string $key, $value, array $attributes): string
    {
        return (string) $value;
    }
}
