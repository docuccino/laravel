<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\EnumCaseRules;

/** Who can see a post: a pure enum, so a case compares as its name. */
enum PostVisibility
{
    case Public;
    case Unlisted;
}
