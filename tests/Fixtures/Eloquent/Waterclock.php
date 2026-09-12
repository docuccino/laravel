<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * {@see Hourglass} without the `serializeDate()` override. The two share ONE column — `posted_at`, a
 * docblock-tagged `$dates` entry at the same Carbon type — and that column is the whole of what the
 * pair compares: the rest differs, timestamps and column set included. Only ever reflected.
 *
 * `sealed_at` is the other way a date column arrives tagged: by the type the DB column has rather than
 * the one the attribute holds, which is what a hand-written docblock usually says. Neither tag is what
 * the response carries.
 *
 * @property CarbonImmutable $posted_at
 * @property string $sealed_at
 */
final class Waterclock extends Model
{
    /** No timestamp columns, so the tagged `$dates` column is the only date attribute. */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $dates = ['posted_at', 'sealed_at'];
}
