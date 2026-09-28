<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Belongs to some kind of media, named by the abstract base its kinds extend. Only ever reflected —
 * never queried.
 *
 * @property int $id
 * @property int $likeable_id
 * @property string $likeable_type
 */
final class Like extends Model
{
    /** @return MorphTo<Media, $this> */
    public function likeable(): MorphTo
    {
        return $this->morphTo();
    }
}
