<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Model;

/** A book naming a collection keying its rows by `#[CollectedBy]`. */
#[CollectedBy(KeyingCollection::class)]
final class CollectedBook extends Model
{
    public $timestamps = false;

    protected $table = 'books';
}
