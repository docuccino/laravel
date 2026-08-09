<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;

/**
 * A Data class with camelCase property names and NO class-level name mapper, so the global default
 * strategy (`config('data.name_mapping_strategy.{input,output}')`) governs its keys — except
 * `userName`, whose explicit `#[MapName]` still wins over the global default (spatie's
 * NameMappersResolver only falls back to the config when no map attribute is present). Only reflected.
 */
final class PlainCasedData extends Data
{
    public function __construct(
        public int $id,
        public string $displayName,
        #[MapName('handle')]
        public string $userName,
    ) {}
}
