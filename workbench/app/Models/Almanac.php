<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Workbench\App\Support\StampedDate;

/**
 * {@see Journal} without the `serializeDate()` override, tagged the same way: `php artisan
 * ide-helper:models` types every date column with the Carbon class the attribute holds. The two are not
 * otherwise a matched pair — this one carries more columns, reaches the date policy through `$dates` and
 * `date` casts where Journal reaches it through `datetime` ones, and is bound on two columns rather than
 * one — so what the override costs is read per column and not by diffing the two models.
 *
 * `observed_on` is the column bound on a `date` cast: the response sends the date-time the framework
 * writes for it and the segment carries the date it is stored as, so the document shows both answers
 * for one column. `closed_on` is the date cast that names its own bespoke pattern, so no `format`
 * keyword describes what it writes. The last two columns are typed by hand rather than by ide-helper,
 * at the two date types whose wire form a declaration cannot state. Never queried.
 *
 * @property int $id
 * @property string $title
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon $recorded_on
 * @property Carbon $observed_on
 * @property Carbon $closed_on
 * @property StampedDate $stamped_on
 * @property DateTimeInterface $noted_at
 */
final class Almanac extends Model
{
    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $dates = ['recorded_on'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'observed_on' => 'date',
        'closed_on' => 'date:d/m/Y',
    ];
}
