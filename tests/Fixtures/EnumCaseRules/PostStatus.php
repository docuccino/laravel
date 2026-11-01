<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\EnumCaseRules;

/** Where a post is in its life: a string-backed enum, so a rule naming a case compares its backing value. */
enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
