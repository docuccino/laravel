<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/** A collection keyed by string — wrapped at runtime exactly as a list is. */
final class NestedWrapMapData extends Data
{
    /** @param array<string, NestedWrapItemData> $things */
    public function __construct(public array $things) {}
}
