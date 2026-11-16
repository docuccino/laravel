<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;

/** A van whose plate is renamed by a mapper the application wrote, which this cannot read. */
final class OwnMapperVanData extends Data
{
    public function __construct(
        public readonly int $id,
        #[MapOutputName(ReversingMapper::class)]
        public readonly string $plate,
    ) {}
}
