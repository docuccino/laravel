<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A model with `date`-cast columns and no `serializeDate()` override — the one cast whose two
 * directions differ. Laravel rounds the value to start-of-day and then serialises it through the hook
 * like any other date, so the response body carries a full date-time while a filter or a bound segment
 * carries the date the column is stored as.
 *
 * One column is tagged the way `php artisan ide-helper:models` tags it and one is not, so the cast is
 * read both where a docblock named the column and where only the cast key evidences it. Only ever
 * reflected.
 *
 * @property int $id The astrolabe id.
 * @property string $title The astrolabe title.
 * @property Carbon $sighted_on
 */
final class Astrolabe extends Model
{
    /** No timestamp columns, so the date-cast columns are the only date attributes there are. */
    public $timestamps = false;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sighted_on' => 'date',
        'filed_on' => 'immutable_date',
    ];
}
