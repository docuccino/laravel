<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TypedChoices;

use Docuccino\Attributes\Description;

/** Off or on: an int-backed enum whose cases are the two integers a boolean is read as. */
#[Description(text: 'Whether the switch is on.')]
enum Toggle: int
{
    case Off = 0;
    case On = 1;
}
