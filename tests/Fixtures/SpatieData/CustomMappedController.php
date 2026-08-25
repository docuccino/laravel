<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

final class CustomMappedController
{
    public function store(CustomMappedData $data): CustomMappedData
    {
        return $data;
    }
}
