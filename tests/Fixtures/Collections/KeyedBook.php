<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/** A book whose `newCollection()` keys its rows by id. */
final class KeyedBook extends Model
{
    public $timestamps = false;

    protected $table = 'books';

    public function newCollection(array $models = [])
    {
        return new Collection(collect($models)->keyBy('id')->all());
    }
}
