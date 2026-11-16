<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Illuminate\Database\Eloquent\Model;

/**
 * A model whose attributes keep the names they are asked for, so laravel-data reads `fleetNumber` and not
 * `fleet_number` off it. Never queried.
 *
 * @property int $id
 * @property string $fleetNumber
 */
final class LiteralVan extends Model
{
    public static $snakeAttributes = false;

    protected $guarded = [];
}
