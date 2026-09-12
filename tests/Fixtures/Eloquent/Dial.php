<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * One column per form a date cast can take, so a test can read what each one SERIALISES to out of
 * Laravel rather than out of the cast table. Paired with {@see Gnomon}, which is this model plus a
 * `serializeDate()` override and nothing else, so a column whose bytes differ between the two is a
 * column the hook really governs.
 *
 * `$dateFormat` is fixed so casting a raw value needs no database connection; the raw value carries a
 * TIME, which is how a `date` cast's start-of-day rounding shows up in the bytes.
 */
class Dial extends Model
{
    /** The stored value every column is read from — one instant, with a time on it. */
    public const RAW = '2024-01-01 13:45:07';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'dated' => 'date',
        'dated_immutable' => 'immutable_date',
        'stamped' => 'datetime',
        'stamped_immutable' => 'immutable_datetime',
        // The INTERNAL cast-type name, which an application can nonetheless write in `$casts`.
        'internal_named' => 'custom_datetime',
        'patterned' => 'datetime:d/m/Y',
        'patterned_date' => 'date:Y-m-d',
        'patterned_date_bespoke' => 'date:d/m/Y',
        'patterned_immutable' => 'immutable_datetime:d/m/Y',
        'unixed' => 'timestamp',
    ];
}
