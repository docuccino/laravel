<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

final class MergedRulesController
{
    public function store(InheritedMergeRulesData $data): InheritedMergeRulesData
    {
        return $data;
    }
}
