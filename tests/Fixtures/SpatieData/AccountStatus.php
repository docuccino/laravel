<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

/** A backed enum used to exercise enum-typed Data property recovery. */
enum AccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
