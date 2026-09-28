<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A polymorphic relation a model takes from a trait. */
trait HasOwner
{
    /** @return MorphTo<User, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
