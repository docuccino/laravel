<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Illuminate\Database\Eloquent\Model;

/**
 * A model routes bind by its key and by its tracking code. Never queried.
 *
 * @property int $id
 * @property string $tracking
 * @property string $label
 */
final class Parcel extends Model
{
    protected $guarded = [];
}
