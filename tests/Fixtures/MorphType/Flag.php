<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Belongs to a model one of whose kinds names its own morph type. Only ever reflected — never queried.
 *
 * @property int $id
 * @property int $flaggable_id
 * @property string $flaggable_type
 */
final class Flag extends Model
{
    /** @return MorphTo<Post|Archive, $this> */
    public function flaggable(): MorphTo
    {
        return $this->morphTo();
    }
}
