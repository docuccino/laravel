<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Two relations over each of two type columns: one pair naming a model each, one pair where either side
 * names any model. Only ever reflected — never queried.
 *
 * @property int $id
 */
final class Share extends Model
{
    /** @return MorphTo<Post, $this> */
    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Video, $this> */
    public function shareableVideo(): MorphTo
    {
        return $this->morphTo('shareable');
    }

    /** @return MorphTo<Post, $this> */
    public function sharedPost(): MorphTo
    {
        return $this->morphTo('shared');
    }

    /** @return MorphTo<Model, $this> */
    public function shared(): MorphTo
    {
        return $this->morphTo();
    }
}
