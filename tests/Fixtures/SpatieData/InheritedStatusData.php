<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

/** Says nothing about its status; {@see ApiStatusBaseData} says it for every endpoint returning one. */
final class InheritedStatusData extends ApiStatusBaseData
{
    public function __construct(
        public string $id,
    ) {}
}
