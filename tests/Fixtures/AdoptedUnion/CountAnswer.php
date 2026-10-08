<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\AdoptedUnion;

final readonly class CountAnswer implements Answer
{
    public ShapeKind $kind;

    public function __construct(public int $value)
    {
        $this->kind = ShapeKind::Count;
    }
}
