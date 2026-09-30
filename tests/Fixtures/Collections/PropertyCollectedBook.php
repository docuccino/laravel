<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Model;

/** A book naming a collection keying its rows by its `$collectionClass`. */
final class PropertyCollectedBook extends Model
{
    public $timestamps = false;

    protected $table = 'books';

    protected static string $collectionClass = KeyingCollection::class;
}
