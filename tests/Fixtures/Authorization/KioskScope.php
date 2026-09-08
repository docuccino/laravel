<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

/** Stands in for whatever ambient state a policy method might consult instead of the user. */
final class KioskScope
{
    public static function current(): ?self
    {
        return null;
    }
}
