<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Belongs to any model — the relation's generic is the framework's own `Model`, as the analyser asks
 * for. Only ever reflected — never queried.
 *
 * @property int $id
 * @property int $reactable_id
 * @property string $reactable_type
 */
final class Reaction extends Model
{
    /** @return MorphTo<Model, $this> */
    public function reactable(): MorphTo
    {
        return $this->morphTo();
    }
}
