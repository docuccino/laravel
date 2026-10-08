<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\AdoptedUnion;

final readonly class MeasureAnswer implements Answer
{
    public ShapeKind $kind;

    /** @param list<int> $steps */
    public function __construct(public float $value, public array $steps)
    {
        $this->kind = ShapeKind::Measure;
    }
}
