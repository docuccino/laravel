<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Illuminate\Database\Eloquent\Model;

/**
 * A model that routes look up by its tag, which it says by overriding `getRouteKeyName()`. Never queried.
 *
 * @property int $id
 * @property string $tag
 */
final class Pallet extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'tag';
    }
}
