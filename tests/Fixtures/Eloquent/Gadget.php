<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * A model fixture with a `$visible` allow-list — only the listed columns are documented, whatever
 * else the engine reports. Only ever reflected.
 */
final class Gadget extends Model
{
    /**
     * @var list<string>
     */
    protected $visible = ['id', 'name'];
}
