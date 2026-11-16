<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TypedChoices;

/** A string-backed enum whose cases read as numbers, two of them spelled as no integer prints. */
enum Measure: string
{
    case One = '1.0';
    case Half = '0.5';
    case Two = '2';
}
