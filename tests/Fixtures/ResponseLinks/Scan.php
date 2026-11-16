<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scan belongs to the parcel it was taken of. Never queried.
 *
 * @property int $id
 * @property int $parcel_id
 * @property string $place
 */
final class Scan extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Parcel, $this> */
    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }
}
