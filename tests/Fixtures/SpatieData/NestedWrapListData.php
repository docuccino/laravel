<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/** A nested collection written as a plain array with a recovered generic — the shape most apps write. */
final class NestedWrapListData extends Data
{
    /** @param list<NestedWrapItemData> $things */
    public function __construct(public array $things) {}
}
