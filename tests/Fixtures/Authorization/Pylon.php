<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Authorized through the model's own `#[UsePolicy]` attribute — the resolution step the Gate keeps
 * protected, so nothing but that attribute can name {@see PylonAccess} for it.
 *
 * @property int $id
 */
#[UsePolicy(PylonAccess::class)]
final class Pylon extends Model {}
