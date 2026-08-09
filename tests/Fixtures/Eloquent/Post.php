<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * A related model a `$with` eager load resolves to (the to-many side). Documented idiomatically with
 * `@property` tags; only ever reflected — never queried.
 *
 * @property int $id The post id.
 * @property string $title The post title.
 */
final class Post extends Model
{
    public $timestamps = false;
}
