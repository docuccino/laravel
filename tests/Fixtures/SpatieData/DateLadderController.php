<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

final class DateLadderController
{
    public function store(DateLadderData $data): DateLadderData
    {
        return $data;
    }
}
