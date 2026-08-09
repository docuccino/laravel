<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

/** Type-hints {@see CustomRuleData}, so its `#[Rule(new …)]` objects reach the request recovery. */
final class CustomRuleController
{
    public function store(CustomRuleData $data): CustomRuleData
    {
        return $data;
    }
}
