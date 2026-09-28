<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A polymorphic relation whose type column an accessor serialises in its place. Only ever reflected —
 * never queried.
 *
 * @property int $id
 * @property int $mentionable_id
 * @property string $mentionable_type
 */
final class Mention extends Model
{
    /** @return MorphTo<Post, $this> */
    public function mentionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getMentionableTypeAttribute(string $value): string
    {
        return strtoupper($value);
    }
}
