<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The shape a `serializeDate()` override usually arrives in: a shared base every model of an
 * application extends, so the override is INHERITED by subclasses that have no date attribute of
 * their own ({@see Signpost}). Only ever reflected.
 */
abstract class Sundial extends Model
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('d/m/Y');
    }
}
