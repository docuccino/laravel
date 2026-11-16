<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Data;

/** A van whose constructor does work of its own, so what it keeps may not be what it was handed. */
final class ConstructingVanData extends Data
{
    public string $plate;

    public function __construct(
        public int $id,
        string $plate,
    ) {
        $this->plate = strtoupper($plate);
    }
}
