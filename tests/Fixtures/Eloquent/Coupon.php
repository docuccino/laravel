<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * A model with a non-incrementing string primary key (e.g. a natural code), so its route key is a
 * plain string with no format. Only ever reflected — never queried.
 *
 * @property string $code The string primary key.
 */
final class Coupon extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;
}
