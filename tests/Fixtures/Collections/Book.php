<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Model;

/** A book whose model configures nothing about its collections. */
final class Book extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
