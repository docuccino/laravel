<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Data;

/** A van as a response sends it: each property a plain copy of the column of its name. */
final class VanData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $plate,
        public readonly string $colour,
    ) {}
}
