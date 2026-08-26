<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

/** Type-hints {@see MappedInputData}, so its input-name mapping reaches the request extension. */
final class MappedInputController
{
    public function store(MappedInputData $data): MappedInputData
    {
        return $data;
    }
}
