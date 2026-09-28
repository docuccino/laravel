<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

use Illuminate\Database\Eloquent\Model;

/**
 * A model a polymorphic relation can point at, open to subclasses. Only ever reflected — never queried.
 *
 * @property int $id
 */
class User extends Model {}
