<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Illuminate\Database\Eloquent\Model;

/**
 * Authorized by a policy registered EXPLICITLY with `Gate::policy()`, which no naming convention would
 * find: its policy sits outside the `Policies` namespace on purpose.
 *
 * @property int $id
 */
final class Marquee extends Model {}
