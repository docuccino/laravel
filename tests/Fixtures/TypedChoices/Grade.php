<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TypedChoices;

/** A grade from one to three: an int-backed enum. */
enum Grade: int
{
    case Pass = 1;
    case Merit = 2;
    case Distinction = 3;
}
