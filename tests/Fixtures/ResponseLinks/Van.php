<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A model routes bind by its key and by its plate, which Data classes send back. Its colour comes through
 * an accessor and its registration date through a cast, so neither reaches the wire as stored. Never queried.
 *
 * @property int $id
 * @property string $plate
 * @property string $fleet_number
 * @property string $colour
 * @property int $mileage
 * @property string $depot
 * @property string $make
 * @property string $model_name
 * @property string $vin
 * @property float $weight
 */
final class Van extends Model
{
    protected $guarded = [];

    protected $casts = ['mileage' => 'integer', 'registered_at' => 'date'];

    public function getColourAttribute(string $value): string
    {
        return ucfirst($value);
    }

    /**
     * Shares its name with a column, so a loaded relation is what a read of that name hands over.
     *
     * @return BelongsTo<Pallet, $this>
     */
    public function depot(): BelongsTo
    {
        return $this->belongsTo(Pallet::class);
    }
}
