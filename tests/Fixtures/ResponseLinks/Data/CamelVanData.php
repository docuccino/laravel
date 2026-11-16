<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/** A van whose class camel-cases each name: unless a global strategy decides first. */
#[MapName(CamelCaseMapper::class)]
final class CamelVanData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $fleet_number,
    ) {}
}
