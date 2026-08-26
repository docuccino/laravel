<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

final class DescribedInputController
{
    public function store(DescribedInputData $data): DescribedInputData
    {
        return $data;
    }
}
