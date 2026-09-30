<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Collections;

/** A trait handing its model's rows to a collection keying them by id, as a shared concern writes it. */
trait CollectsKeyed
{
    public function newCollection(array $models = [])
    {
        return new KeyingCollection($models);
    }
}
