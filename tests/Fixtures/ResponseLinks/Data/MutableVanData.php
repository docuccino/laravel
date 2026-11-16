<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Data;

/** A van whose properties are writable, so an action may set one again after building it. */
final class MutableVanData extends Data
{
    public function __construct(
        public int $id,
        public string $plate,
    ) {}
}
