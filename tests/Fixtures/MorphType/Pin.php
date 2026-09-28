<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The other spellings of a type column: a named `type:`, an explicit relation name, an explicit `null`,
 * and the calls that can't be read — a body choosing between two relations at runtime, an unpack, and an
 * argument `morphTo()` has no parameter for. Only ever reflected — never queried.
 *
 * @property int $id
 */
final class Pin extends Model
{
    /** @return MorphTo<Post, $this> */
    public function pinnable(): MorphTo
    {
        return $this->morphTo(type: 'pinned_type');
    }

    /** @return MorphTo<Video, $this> */
    public function board(): MorphTo
    {
        return $this->morphTo('surface');
    }

    /** @return MorphTo<Post, $this> */
    public function either(): MorphTo
    {
        return $this->leftHanded() ? $this->morphTo('left') : $this->morphTo('right');
    }

    /** @return MorphTo<Post, $this> */
    public function spelledNull(): MorphTo
    {
        return $this->morphTo(null, 'nulled_kind');
    }

    /** @return MorphTo<Post, $this> */
    public function spread(): MorphTo
    {
        return $this->morphTo(...['spread']);
    }

    /** @return MorphTo<Post, $this> */
    public function misnamed(): MorphTo
    {
        return $this->morphTo(label: 'misnamed');
    }

    private function leftHanded(): bool
    {
        return false;
    }
}
