<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/** The item of every nested collection the wrap diagnostic is proven against. */
final class NestedWrapItemData extends Data
{
    public function __construct(public string $label) {}
}
