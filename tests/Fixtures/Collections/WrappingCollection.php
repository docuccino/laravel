<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Collections;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * A collection sending its items under a `data` key: what it is sent as is the override's answer.
 *
 * @template TKey of array-key
 * @template TModel of Model
 *
 * @extends Collection<TKey, TModel>
 */
final class WrappingCollection extends Collection
{
    /** @return array{data: array<array-key, mixed>} */
    public function jsonSerialize(): array
    {
        return ['data' => parent::jsonSerialize()];
    }
}
