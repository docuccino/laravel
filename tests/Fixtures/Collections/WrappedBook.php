<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Model;

/** A book naming a collection sending its rows under a `data` key. */
final class WrappedBook extends Model
{
    public $timestamps = false;

    protected $table = 'books';

    protected static string $collectionClass = WrappingCollection::class;
}
