<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Illuminate\Database\Eloquent\Model;

/**
 * Authorized by a policy that carries a `before()` method, which can answer for every ability it
 * covers — so no body of that policy settles whether the gate can deny.
 *
 * @property int $id
 */
final class Placard extends Model {}
