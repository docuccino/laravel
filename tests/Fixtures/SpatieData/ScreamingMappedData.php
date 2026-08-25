<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;

/**
 * A Data class keyed by an application-defined mapper, plus one property naming a mapper class that no
 * installed version ships. Neither can be read statically, so the keys degrade — to the property name
 * and to the literal respectively, which is what spatie itself would key them as.
 */
#[MapName(ScreamingNameMapper::class)]
final class ScreamingMappedData extends Data
{
    public function __construct(
        public string $displayName,
        #[MapOutputName('Spatie\\LaravelData\\Mappers\\NotShippedYetMapper')]
        public string $userName,
    ) {}
}
