<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * A collection keying whatever it is built with by id, so every `new static` a call makes is keyed too.
 *
 * @template TKey of array-key
 * @template TModel of Model
 *
 * @extends Collection<TKey, TModel>
 */
final class KeyingCollection extends Collection
{
    /** @param  iterable<TKey, TModel>  $items */
    public function __construct($items = [])
    {
        parent::__construct(collect($items)->keyBy('id')->all());
    }
}
