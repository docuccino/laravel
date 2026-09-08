<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Illuminate\Database\Eloquent\Model;

/**
 * Gated with no policy behind it at all — no registration, and nothing at the name the convention
 * looks under. The commonest shape a real application has, and the one where the check has nothing to
 * read.
 *
 * @property int $id
 */
final class Awning extends Model {}
