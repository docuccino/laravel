<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

use Illuminate\Database\Eloquent\Model;

/**
 * A model that names its own morph type, whatever the map says. Only ever reflected — never queried.
 *
 * @property int $id
 */
final class Archive extends Model
{
    public function getMorphClass(): string
    {
        return 'archived';
    }
}
