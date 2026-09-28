<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One readable polymorphic relation beside one whose name is computed, so its columns are unknown.
 * Only ever reflected — never queried.
 *
 * @property int $id
 * @property int $subject_id
 * @property string $subject_type
 */
final class Note extends Model
{
    /** @return MorphTo<Post, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Video, $this> */
    public function context(): MorphTo
    {
        return $this->morphTo($this->contextName());
    }

    private function contextName(): string
    {
        return 'subject';
    }
}
