<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

/** Returns the payload whose success status is decided by a base class rather than by itself. */
final class InheritedStatusController
{
    public function store(): InheritedStatusData
    {
        return new InheritedStatusData('a');
    }
}
