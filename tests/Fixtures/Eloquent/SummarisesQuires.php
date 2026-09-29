<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Casts\Attribute;

/** An `Attribute` accessor written in a file of its own, for the model that uses it. */
trait SummarisesQuires
{
    /**
     * @return Attribute<string, never>
     */
    public function summary(): Attribute
    {
        return Attribute::make(get: fn (mixed $value, array $attributes): string => (string) ($attributes['title'] ?? ''));
    }
}
