<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\EnumCaseRules;

/** How soon a post goes out: an int-backed enum, so a case compares as a whole number. */
enum PostPriority: int
{
    case Low = 1;
    case Normal = 2;
    case High = 3;
}
