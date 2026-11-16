<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A van under mapped names: the class snake-cases each name, and the plate is read from `plate` and sent
 * as `registration`, which its own maps say.
 */
#[MapName(SnakeCaseMapper::class)]
final class FleetVanData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $fleetNumber,
        #[MapInputName('plate'), MapOutputName('registration'), Max(12)]
        public readonly string $reg,
    ) {}
}
