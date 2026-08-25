<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

/** Type-hints {@see ScreamingMappedData}, so its unreadable mapper reaches the request extension. */
final class ScreamingMappedController
{
    public function store(ScreamingMappedData $data): ScreamingMappedData
    {
        return $data;
    }
}
