<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Data;

/** A van read off a model whose attributes keep the names they are asked for. */
final class LiteralVanData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $fleetNumber,
    ) {}
}
