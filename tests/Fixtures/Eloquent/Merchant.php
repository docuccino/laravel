<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * A related model a `$with` eager load resolves to (the to-one side). Documented idiomatically with
 * `@property` tags; only ever reflected — never queried.
 *
 * @property int $id The merchant id.
 * @property string $name The merchant name.
 */
final class Merchant extends Model
{
    public $timestamps = false;
}
