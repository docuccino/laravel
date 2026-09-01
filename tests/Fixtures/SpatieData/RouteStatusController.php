<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

/** Three routes onto one conditional-status Data class: the named create, a sibling POST, and a read. */
final class RouteStatusController
{
    public function store(): RouteStatusData
    {
        return new RouteStatusData('1');
    }

    public function publish(): RouteStatusData
    {
        return new RouteStatusData('1');
    }

    public function show(): RouteStatusData
    {
        return new RouteStatusData('1');
    }
}
