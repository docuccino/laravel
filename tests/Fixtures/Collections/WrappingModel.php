<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Model;

/** A base model every subclass inherits a wrapping `$collectionClass` from. */
abstract class WrappingModel extends Model
{
    protected static string $collectionClass = WrappingCollection::class;
}
