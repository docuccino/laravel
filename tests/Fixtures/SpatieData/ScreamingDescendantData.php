<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

/** Inherits an unreadable mapper and says nothing about mapping itself. */
final class ScreamingDescendantData extends ScreamingAncestorData
{
    public function __construct(
        public string $displayName,
    ) {}
}
