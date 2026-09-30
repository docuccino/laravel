<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Model;

/** A book whose `newCollection()` comes from a trait. */
final class TraitCollectedBook extends Model
{
    use CollectsKeyed;

    public $timestamps = false;

    protected $table = 'books';
}
