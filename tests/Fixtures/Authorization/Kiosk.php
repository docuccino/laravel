<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Illuminate\Database\Eloquent\Model;

/**
 * The model a `->can()` route authorizes against. Its policy is found by Laravel's own convention —
 * `Authorization\Policies\KioskPolicy` — so nothing registers it and the resolution under test is the
 * one an application gets for free.
 *
 * @property int $id
 */
final class Kiosk extends Model {}
