<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Tags;

use Docuccino\Core\Extensions\Contracts\TagMapper;

/** A tag mapper whose mapping lives a level up from the class an application configures. */
abstract class BaseTagMapper implements TagMapper
{
    use PrefixesTags;
}
