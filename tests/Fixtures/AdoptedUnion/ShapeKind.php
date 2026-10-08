<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\AdoptedUnion;

enum ShapeKind: string
{
    case Count = 'count';
    case Measure = 'measure';
    case Letters = 'letters';
}
