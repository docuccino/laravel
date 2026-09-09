<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * A base model that names its policy with `#[UsePolicy]` — the shape a subclass inherits nothing from
 * as far as reflection is concerned, since PHP reports a class's attributes and never its parents'.
 * {@see Lightbox} is what makes that matter.
 *
 * @property int $id
 */
#[UsePolicy(TotemAccess::class)]
class Totem extends Model {}
