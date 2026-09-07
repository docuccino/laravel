<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\ArgumentEvaluated;

use LogicException;

/**
 * A probe for one fact: constructing this says so, loudly, by throwing something no parameter type
 * would ever raise. A helper beside the changes is not a change, so the scan skips it.
 */
final class ThrowsWhenConstructed
{
    public function __construct()
    {
        throw new LogicException('constructed');
    }
}
