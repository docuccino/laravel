<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * A model whose primary key is an app-assigned plain string (`$keyType = 'string'`, non-incrementing)
 * with no key trait fixing a format. Only ever reflected — never queried.
 *
 * @property string $id The assigned card code.
 */
final class Passcard extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';
}
