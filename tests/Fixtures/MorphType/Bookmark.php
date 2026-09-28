<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An optional polymorphic relation whose columns are named explicitly. Only ever reflected — never
 * queried.
 *
 * @property int $id
 * @property int|null $target_ref
 * @property string|null $target_kind
 */
final class Bookmark extends Model
{
    /** @return MorphTo<Post|User, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'target_kind', 'target_ref');
    }
}
