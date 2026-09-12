<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Carbon\CarbonImmutable;

/**
 * A model whose ONE date attribute is shadowed by an accessor, under the {@see Sundial}
 * `serializeDate()` override it inherits. Laravel adds a mutated attribute after the date attributes
 * and never serialises it through the hook, so what this model publishes for `posted_at` is the
 * accessor's own value and the override reaches nothing. Only ever reflected.
 *
 * @property CarbonImmutable $posted_at
 */
final class Sandglass extends Sundial
{
    /** No timestamp columns, so the shadowed column is the only date attribute there is. */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $dates = ['posted_at'];

    public function getPostedAtAttribute(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
