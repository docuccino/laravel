<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Collections;

/** A book inheriting its collection class from its parent. */
final class InheritedWrappedBook extends WrappingModel
{
    public $timestamps = false;

    protected $table = 'books';
}
