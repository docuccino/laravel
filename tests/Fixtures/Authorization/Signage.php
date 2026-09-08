<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Illuminate\Database\Eloquent\Model;

/**
 * Authorized by a conventional policy that DECLARES no ability of its own: every one it answers is
 * inherited from a base class, which is where a reader has to be sent to change the answer.
 *
 * @property int $id
 */
final class Signage extends Model {}
