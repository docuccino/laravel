<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** A van whose plate is renamed by a mapper handed over as an object, which this does not read. */
final class HandedMapperVanData extends Data
{
    public function __construct(
        public readonly int $id,
        #[MapName(new SnakeCaseMapper)]
        public readonly string $fleetNumber,
    ) {}
}
