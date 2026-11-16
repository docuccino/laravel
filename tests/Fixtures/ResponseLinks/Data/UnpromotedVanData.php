<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Data;

/** A van whose constructor takes the plate as a plain parameter, which no property is set from. */
final class UnpromotedVanData extends Data
{
    public string $plate;

    public function __construct(
        public readonly int $id,
        string $plate,
    ) {}
}
