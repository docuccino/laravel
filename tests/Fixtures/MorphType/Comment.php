<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Belongs to one of two models, named by the relation's generic. Only ever reflected — never queried.
 *
 * @property int $id
 * @property string $body
 * @property int $commentable_id
 * @property string $commentable_type
 */
final class Comment extends Model
{
    /** @return MorphTo<Post|Video, $this> */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
