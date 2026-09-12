<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * A model whose date attributes arrive from every source at once, under the {@see Sundial}
 * `serializeDate()` override it inherits: framework timestamps, a `$dates` floor column, and a
 * docblock tag claiming one of each — the shape `php artisan ide-helper:models` writes. Only ever
 * reflected.
 *
 * @property int $id The hourglass id.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property CarbonImmutable $posted_at
 */
final class Hourglass extends Sundial
{
    /**
     * @var list<string>
     */
    protected $dates = ['posted_at'];
}
