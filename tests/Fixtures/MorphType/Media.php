<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

use Illuminate\Database\Eloquent\Model;

/** A base model that is never stored itself — only its subclasses are. */
abstract class Media extends Model {}
