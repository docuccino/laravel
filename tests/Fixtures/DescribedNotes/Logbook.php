<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DescribedNotes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A model whose date casts name their own patterns, so no `format` keyword says what they write, with and
 * without a summary on the column. `$dateFormat` is the format the columns are STORED in and never reaches
 * a response. Never queried.
 *
 * @property int $id
 * @property Carbon $closed_on The day the logbook was closed.
 * @property Carbon $opened_on
 * @property Carbon|null $sealed_on When the logbook was sealed, if it has been.
 * @property Carbon $created_at When the logbook was started.
 * @property Carbon $updated_at
 */
final class Logbook extends Model
{
    protected $guarded = [];

    protected $dateFormat = 'd/m/Y H:i';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'closed_on' => 'date:d/m/Y',
        'opened_on' => 'date:d/m/Y',
        'sealed_on' => 'datetime:d/m/Y H:i',
    ];
}
