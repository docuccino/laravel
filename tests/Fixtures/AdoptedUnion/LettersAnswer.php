<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\AdoptedUnion;

final readonly class LettersAnswer implements Answer
{
    public ShapeKind $kind;

    /** @param list<string> $letters */
    public function __construct(public array $letters)
    {
        $this->kind = ShapeKind::Letters;
    }
}
